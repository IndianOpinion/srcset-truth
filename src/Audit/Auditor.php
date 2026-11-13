<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Audit;

use IndianOpinion\SrcsetTruth\Exception\AuditFailed;
use IndianOpinion\SrcsetTruth\Exception\InvalidSizesSpec;
use IndianOpinion\SrcsetTruth\Files\PathMapper;
use IndianOpinion\SrcsetTruth\Files\Siblings;
use IndianOpinion\SrcsetTruth\Files\SkipLedger;
use IndianOpinion\SrcsetTruth\Http;
use IndianOpinion\SrcsetTruth\Layout\WidthMap;
use IndianOpinion\SrcsetTruth\Markup\Attributes;
use IndianOpinion\SrcsetTruth\Markup\Lcp;
use IndianOpinion\SrcsetTruth\Markup\Srcset;
use IndianOpinion\SrcsetTruth\Plugin;
use IndianOpinion\SrcsetTruth\Sizes\Compiler;
use IndianOpinion\SrcsetTruth\Sizes\CoreDefault;
use IndianOpinion\SrcsetTruth\Sizes\Evaluator;

/**
 * Before-and-after image weight of one page of this site (PRD 4.12).
 *
 * @phpstan-type Pick array{url: string, width: ?int, bytes: ?int, format: string}
 * @phpstan-type Row array{
 *     index: int, src: string, template: ?string, slot: ?string, sizes: ?string,
 *     before: Pick|null, after: Pick|null, flags: list<string>, missing_width: ?int, unknown: bool
 * }
 * @phpstan-type Totals array{images: int, counted: int, before_bytes: int, after_bytes: int, saving_kib: float, saving_percent: float}
 * @phpstan-type Report array{url: string, viewport: array{width: float, dpr: float}, images: list<Row>, totals: Totals}
 */
final class Auditor
{
    public const DEFAULT_WIDTH = 412;

    /** Decided in ARCHITECTURE.md: a common phone density, and Lighthouse-like. */
    public const DEFAULT_DPR = 2.0;

    private const NEAR = 1.5;

    /** @var array<string, ?int> */
    private array $bytes = [];

    private string $finalUrl = '';

    public function __construct(private readonly float $width = self::DEFAULT_WIDTH, private readonly float $dpr = self::DEFAULT_DPR)
    {
        if ($width < 1 || $width > 10000 || $dpr <= 0 || $dpr > 10) {
            throw new AuditFailed('Width must be 1 to 10000 px and DPR above 0 and at most 10.');
        }
    }

    private static function homeHost(): string
    {
        $home = parse_url((string) home_url('/'));

        return is_array($home) ? strtolower((string) ($home['host'] ?? '')) : '';
    }

    /**
     * A path or a URL on this site's own host. Anything else is refused.
     */
    public static function resolveUrl(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return (string) home_url('/');
        }
        if (str_starts_with($target, '/') && !str_starts_with($target, '//')) {
            return (string) home_url($target);
        }
        $parts = parse_url($target);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new AuditFailed('Give a path such as /about/ or a full http(s) URL on this site.');
        }
        if (strtolower($parts['host']) !== self::homeHost()) {
            throw new AuditFailed(sprintf('Refusing to fetch %s: the audit only fetches this site (%s).', $parts['host'], self::homeHost()));
        }

        return $target;
    }

    /**
     * @return Report
     */
    public function run(string $target): array
    {
        $url = self::resolveUrl($target);
        $html = $this->fetch($url);

        return $this->analyse($html, $this->finalUrl);
    }

    public function fetch(string $url): string
    {
        for ($hop = 0; $hop <= 3; $hop++) {
            $response = wp_remote_get($url, [
                'timeout' => 15,
                'redirection' => 0,
                'user-agent' => 'srcset-truth-audit/' . Plugin::VERSION,
                'headers' => [Plugin::AUDIT_HEADER => Plugin::auditToken()],
            ]);
            if (is_wp_error($response)) {
                throw new AuditFailed('Could not fetch ' . $url . ': ' . $response->get_error_message());
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status >= 300 && $status < 400) {
                $location = Http::header($response, 'location');
                if ($location === '') {
                    throw new AuditFailed(sprintf('HTTP %d without a Location header.', $status));
                }
                if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
                    $location = (string) home_url($location);
                }
                $next = parse_url(str_starts_with($location, '//') ? 'https:' . $location : $location);
                if (!is_array($next) || strtolower((string) ($next['host'] ?? '')) !== self::homeHost()) {
                    throw new AuditFailed(sprintf('HTTP %d redirect to another host refused: %s', $status, $location));
                }
                $url = $location;
                continue;
            }
            if ($status !== 200) {
                throw new AuditFailed(sprintf('HTTP %d for %s', $status, $url));
            }
            $type = Http::header($response, 'content-type');
            if ($type !== '' && stripos($type, 'html') === false) {
                throw new AuditFailed(sprintf('Not HTML (%s): %s', $type, $url));
            }

            $this->finalUrl = $url;

            return (string) wp_remote_retrieve_body($response);
        }
        throw new AuditFailed('Too many redirects for ' . $url);
    }

    /**
     * @return Report
     */
    public function analyse(string $html, string $url = ''): array
    {
        $rows = [];
        $p = new \WP_HTML_Tag_Processor($html);
        $inPicture = 0;
        $skip = 0;
        $webpSource = null;
        $index = -1;
        $lcpFound = false;
        while ($p->next_tag(['tag_closers' => 'visit'])) {
            $tag = (string) $p->get_tag();
            $closer = $p->is_tag_closer();
            if ($tag === 'TEMPLATE' || $tag === 'SVG') {
                $skip = max(0, $skip + ($closer ? -1 : 1));
                continue;
            }
            if ($tag === 'PICTURE') {
                $inPicture = max(0, $inPicture + ($closer ? -1 : 1));
                $webpSource = null;
                continue;
            }
            if ($tag === 'SOURCE' && !$closer && $inPicture > 0) {
                $type = Attributes::string($p, 'type');
                if ($type !== null && strtolower(trim($type)) === 'image/webp') {
                    $webpSource = ['srcset' => (string) Attributes::string($p, 'srcset'), 'sizes' => Attributes::string($p, 'sizes')];
                }
                continue;
            }
            if ($tag !== 'IMG' || $closer || $skip > 0) {
                continue;
            }
            $index++;
            $attrs = Attributes::read($p);
            $row = $this->row($index, $attrs, $inPicture > 0 ? $webpSource : null);
            if (!$lcpFound && Lcp::qualifies($attrs, $index)) {
                $lcpFound = true;
                if (is_string($attrs['loading'] ?? null) && strtolower($attrs['loading']) === 'lazy') {
                    $row['flags'][] = 'lazy-lcp';
                }
            }
            $rows[] = $row;
        }

        return [
            'url' => $url,
            'viewport' => ['width' => $this->width, 'dpr' => $this->dpr],
            'images' => $rows,
            'totals' => self::totals($rows),
        ];
    }

    /**
     * @param array<string, string|true> $attrs
     * @param array{srcset: string, sizes: ?string}|null $webpSource
     * @return Row
     */
    private function row(int $index, array $attrs, ?array $webpSource): array
    {
        $src = is_string($attrs['src'] ?? null) ? $attrs['src'] : '';
        $srcset = is_string($attrs['srcset'] ?? null) ? $attrs['srcset'] : '';
        $sizes = is_string($attrs['sizes'] ?? null) ? $attrs['sizes'] : null;
        [$template, $slot, $status] = self::annotation($attrs);
        $flags = [];
        if (!isset($attrs['width']) || !isset($attrs['height'])) {
            $flags[] = 'unsized';
        }

        $imgCandidates = $srcset !== '' ? Srcset::parse($srcset) : ($src !== '' ? [['url' => $src, 'descriptor' => '']] : []);
        $original = array_map(static function (array $c): array {
            $back = Siblings::originalUrl($c['url']);

            return ['url' => $back ?? $c['url'], 'descriptor' => $c['descriptor']];
        }, $imgCandidates);
        $after = $imgCandidates;
        $afterSizes = $sizes;
        if ($webpSource !== null && $webpSource['srcset'] !== '') {
            $after = Srcset::parse($webpSource['srcset']);
            $afterSizes = $webpSource['sizes'] ?? $sizes;
        }

        $widthAttr = is_string($attrs['width'] ?? null) && preg_match('/^\s*(\d+)/', $attrs['width'], $m) === 1 ? (int) $m[1] : null;
        $default = $sizes !== null ? CoreDefault::parse($sizes) : null;
        $imageWidth = $default['width'] ?? $widthAttr ?? self::largestWidth($original);

        $result = [
            'index' => $index,
            'src' => $src,
            'template' => $template,
            'slot' => $slot,
            'sizes' => $sizes,
            'before' => null,
            'after' => null,
            'flags' => $flags,
            'missing_width' => null,
            'unknown' => false,
        ];
        $mapFlags = self::mapFlags($status, $srcset !== '' ? $sizes : null, $default, $imageWidth, $template);
        if ($original === [] || $after === []) {
            $result['unknown'] = true;
            $result['flags'] = array_values(array_unique(array_merge($flags, $mapFlags)));

            return $result;
        }

        $beforeSlot = $imageWidth !== null ? Evaluator::slotWidth(CoreDefault::build($imageWidth), $this->width) : $this->width;
        $afterSlot = Evaluator::slotWidth($afterSizes ?? '100vw', $this->width);
        $beforeIndex = $beforeSlot === null ? null : Picker::pick($original, $beforeSlot, $this->dpr);
        $afterIndex = $afterSlot === null ? null : Picker::pick($after, $afterSlot, $this->dpr);
        if ($beforeIndex === null || $afterIndex === null) {
            $result['unknown'] = true;
            $result['flags'] = array_values(array_unique(array_merge($flags, $mapFlags)));

            return $result;
        }
        $result['before'] = $this->describe($original[$beforeIndex]);
        $result['after'] = $this->describe($after[$afterIndex]);

        $flags = array_merge($flags, $mapFlags);
        $chosenWidth = Srcset::width($after[$afterIndex]['descriptor']);
        $need = $afterSlot * $this->dpr;
        if ($chosenWidth !== null && $chosenWidth > $need * self::NEAR) {
            $flags[] = 'no-candidate-near-slot';
            $result['missing_width'] = (int) ceil($need);
        }
        $originalUrl = Siblings::originalUrl($after[$afterIndex]['url']) ?? $after[$afterIndex]['url'];
        $path = PathMapper::urlToPath($originalUrl);
        if ($path !== null && is_file($path) && Siblings::hasSourceExtension($path) && $result['after']['format'] !== 'webp') {
            $flags[] = SkipLedger::reason($path) === 'not-beneficial' ? 'not-beneficial' : 'no-sibling';
        }
        $result['flags'] = array_values(array_unique($flags));

        return $result;
    }

    /**
     * @param array{width: int, auto: bool}|null $default
     * @return list<string>
     */
    private static function mapFlags(?string $status, ?string $sizes, ?array $default, ?int $imageWidth, ?string $template): array
    {
        if ($status !== null) {
            return in_array($status, ['unmapped', 'authored', 'invalid-spec'], true) ? [$status] : [];
        }
        if ($sizes === null) {
            return [];
        }
        if ($default !== null) {
            return ['unmapped'];
        }

        return self::producedByMap($sizes, $imageWidth, $template ?? 'default') ? [] : ['authored'];
    }

    /**
     * Without an annotation, a non-default value counts as ours when it
     * equals what the current width map compiles to for this image.
     */
    private static function producedByMap(string $sizes, ?int $imageWidth, string $template): bool
    {
        $bare = trim(CoreDefault::stripAuto($sizes));
        foreach (WidthMap::forTemplate($template) as $slots) {
            foreach ($slots as $spec) {
                if (!is_string($spec) && !is_array($spec)) {
                    continue;
                }
                try {
                    if (Compiler::compile($spec, $imageWidth) === $bare) {
                        return true;
                    }
                } catch (InvalidSizesSpec) {
                    continue;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, string|true> $attrs
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private static function annotation(array $attrs): array
    {
        $note = $attrs['data-srcset-truth'] ?? null;
        if (!is_string($note) || substr_count($note, '|') !== 2) {
            return [null, null, null];
        }
        [$template, $slot, $status] = explode('|', $note);

        return [$template !== '' ? $template : null, $slot !== '' ? $slot : null, $status !== '' ? $status : null];
    }

    /**
     * @param list<array{url: string, descriptor: string}> $candidates
     */
    private static function largestWidth(array $candidates): ?int
    {
        $max = null;
        foreach ($candidates as $candidate) {
            $width = Srcset::width($candidate['descriptor']);
            if ($width !== null && ($max === null || $width > $max)) {
                $max = $width;
            }
        }

        return $max;
    }

    /**
     * @param array{url: string, descriptor: string} $candidate
     * @return Pick
     */
    private function describe(array $candidate): array
    {
        return [
            'url' => $candidate['url'],
            'width' => Srcset::width($candidate['descriptor']),
            'bytes' => $this->bytes($candidate['url']),
            'format' => self::format($candidate['url']),
        ];
    }

    public static function format(string $url): string
    {
        $path = strtolower(substr($url, 0, strcspn($url, '?#')));
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return match ($ext) {
            'jpg', 'jpeg', 'jpe' => 'jpeg',
            'png', 'gif', 'webp', 'avif', 'svg' => $ext,
            default => 'unknown',
        };
    }

    /**
     * Local file size, else Content-Length from a same-host HEAD, else null.
     */
    private function bytes(string $url): ?int
    {
        if (array_key_exists($url, $this->bytes)) {
            return $this->bytes[$url];
        }
        $path = PathMapper::urlToPath($url);
        if ($path !== null && is_file($path)) {
            return $this->bytes[$url] = (int) filesize($path);
        }
        $fetch = $url;
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $fetch = (string) home_url($url);
        }
        $target = parse_url(str_starts_with($fetch, '//') ? 'https:' . $fetch : $fetch);
        if (!is_array($target) || strtolower((string) ($target['host'] ?? '')) !== self::homeHost()) {
            return $this->bytes[$url] = null;
        }
        $response = wp_remote_head($fetch, ['timeout' => 5, 'redirection' => 0]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return $this->bytes[$url] = null;
        }
        $length = Http::header($response, 'content-length');

        return $this->bytes[$url] = is_numeric($length) ? (int) $length : null;
    }

    /**
     * @param list<Row> $rows
     * @return Totals
     */
    public static function totals(array $rows): array
    {
        $before = 0;
        $after = 0;
        $counted = 0;
        foreach ($rows as $row) {
            if ($row['unknown'] || $row['before'] === null || $row['after'] === null || $row['before']['bytes'] === null || $row['after']['bytes'] === null) {
                continue;
            }
            $before += $row['before']['bytes'];
            $after += $row['after']['bytes'];
            $counted++;
        }
        $saving = $before - $after;

        return [
            'images' => count($rows),
            'counted' => $counted,
            'before_bytes' => $before,
            'after_bytes' => $after,
            'saving_kib' => round($saving / 1024, 1),
            'saving_percent' => $before > 0 ? round($saving * 100 / $before, 1) : 0.0,
        ];
    }
}

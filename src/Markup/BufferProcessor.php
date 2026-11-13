<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Markup;

use IndianOpinion\SrcsetTruth\Logger;
use IndianOpinion\SrcsetTruth\Plugin;
use IndianOpinion\SrcsetTruth\Sizes\CoreDefault;

/**
 * Buffer mode (PRD 55b) and the LCP fix (4.9): one walk of the whole
 * front-end HTML with the tag processor. A second, read-only walk runs
 * first only when the LCP fix is on, so nothing is changed when no image
 * qualifies (case 62).
 */
final class BufferProcessor
{
    private const SKIP_REGIONS = ['TEMPLATE', 'SVG'];

    public static function process(string $html): string
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return $html;
        }
        try {
            if (!self::looksComplete($html)) {
                Logger::once('buffer-incomplete', 'Buffer is not well formed enough to walk; sent unchanged.');

                return $html;
            }
            $lcpOn = Plugin::lcpEnabled();
            $lcpIndex = $lcpOn ? self::findLcp($html) : null;

            $p = new \WP_HTML_Tag_Processor($html);
            $skipDepth = 0;
            $pictureDepth = 0;
            $inHead = false;
            $hasPreload = false;
            $index = -1;
            $wraps = [];
            $chosen = null;
            while ($p->next_tag(['tag_closers' => 'visit'])) {
                $tag = (string) $p->get_tag();
                $closer = $p->is_tag_closer();
                if (in_array($tag, self::SKIP_REGIONS, true)) {
                    $skipDepth = max(0, $skipDepth + ($closer ? -1 : 1));
                    continue;
                }
                if ($tag === 'PICTURE') {
                    $pictureDepth = max(0, $pictureDepth + ($closer ? -1 : 1));
                    continue;
                }
                if ($tag === 'HEAD') {
                    $inHead = !$closer;
                    continue;
                }
                if ($tag === 'LINK' && !$closer && $inHead && self::isImagePreload($p)) {
                    $hasPreload = true;
                    continue;
                }
                if ($tag !== 'IMG' || $closer || $skipDepth > 0) {
                    continue;
                }
                $index++;
                $loading = null;
                if ($lcpIndex !== null) {
                    $loading = $index === $lcpIndex ? 'lcp' : 'lazy';
                }
                $wrapId = Wrapper::nextId();
                $outcome = ImageProcessor::process($p, ['source' => 'buffer'], [
                    'loading' => $loading,
                    'inside_picture' => $pictureDepth > 0,
                    'fill_dimensions' => $lcpOn,
                    'wrap_id' => $wrapId,
                ]);
                if ($outcome['wrap'] !== null) {
                    $wraps[$wrapId] = $outcome['wrap'];
                }
                if ($index === $lcpIndex) {
                    $chosen = ['attrs' => $outcome['attrs'], 'wrap' => $outcome['wrap']];
                }
            }
            if (self::pausedIncomplete($p)) {
                Logger::once('buffer-incomplete', 'Buffer ends inside a tag; sent unchanged.');

                return $html;
            }
            $out = $p->get_updated_html();
            if ($wraps !== []) {
                $out = Wrapper::apply($out, $wraps);
            }
            if ($chosen !== null && !$hasPreload) {
                $out = self::injectPreload($out, $chosen['attrs'], $chosen['wrap']);
            }

            return $out;
        } catch (\Throwable $e) {
            Logger::once('buffer-error', 'Buffer processing failed, sent unchanged: ' . $e->getMessage());

            return $html;
        }
    }

    /**
     * Index of the LCP image among IMG tags outside skipped regions, or null.
     */
    public static function findLcp(string $html): ?int
    {
        $p = new \WP_HTML_Tag_Processor($html);
        $skipDepth = 0;
        $index = -1;
        while ($p->next_tag(['tag_closers' => 'visit'])) {
            $tag = (string) $p->get_tag();
            if (in_array($tag, self::SKIP_REGIONS, true)) {
                $skipDepth = max(0, $skipDepth + ($p->is_tag_closer() ? -1 : 1));
                continue;
            }
            if ($tag !== 'IMG' || $p->is_tag_closer() || $skipDepth > 0) {
                continue;
            }
            $index++;
            if (Lcp::qualifies(Attributes::read($p), $index)) {
                return $index;
            }
        }

        return null;
    }

    private static function isImagePreload(\WP_HTML_Tag_Processor $p): bool
    {
        $rel = Attributes::string($p, 'rel');
        $as = Attributes::string($p, 'as');
        if ($rel === null || $as === null) {
            return false;
        }

        return in_array('preload', Attributes::classes(strtolower($rel)), true) && strtolower(trim($as)) === 'image';
    }

    /**
     * Case 61: preload the chosen image before </head>.
     *
     * @param array<string, string|true> $attrs
     * @param array{srcset: string, sizes: ?string}|null $wrap
     */
    private static function injectPreload(string $html, array $attrs, ?array $wrap): string
    {
        $head = stripos($html, '</head>');
        if ($head === false) {
            return $html;
        }
        $src = is_string($attrs['src'] ?? null) ? $attrs['src'] : '';
        $srcset = $wrap !== null ? $wrap['srcset'] : (is_string($attrs['srcset'] ?? null) ? $attrs['srcset'] : '');
        $sizes = is_string($attrs['sizes'] ?? null) ? trim(CoreDefault::stripAuto($attrs['sizes'])) : '';
        if ($src === '' && $srcset === '') {
            return $html;
        }
        $href = $src;
        if ($wrap !== null) {
            $sibling = WebpPlanner::siblingFor($src);
            $href = $sibling ?? ($srcset === '' ? $src : '');
        }
        $link = '<link rel="preload" as="image" fetchpriority="high"';
        if ($href !== '') {
            $link .= ' href="' . esc_url($href) . '"';
        }
        if ($srcset !== '') {
            $link .= ' imagesrcset="' . esc_attr($srcset) . '"';
            if ($sizes !== '') {
                $link .= ' imagesizes="' . esc_attr($sizes) . '"';
            }
        }
        if ($wrap !== null) {
            $link .= ' type="image/webp"';
        }
        $link .= ">\n";

        return substr_replace($html, $link, $head, 0);
    }

    /**
     * A buffer that stops inside a tag cannot be walked safely. WordPress
     * 6.5 and later also say so after the walk (paused_at_incomplete_token).
     */
    private static function pausedIncomplete(object $p): bool
    {
        return method_exists($p, 'paused_at_incomplete_token') && (bool) $p->paused_at_incomplete_token();
    }

    private static function looksComplete(string $html): bool
    {
        $lastOpen = strrpos($html, '<');
        $lastClose = strrpos($html, '>');

        return $lastOpen === false || ($lastClose !== false && $lastClose > $lastOpen);
    }
}

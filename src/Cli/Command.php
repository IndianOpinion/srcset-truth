<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Cli;

use IndianOpinion\SrcsetTruth\Audit\Auditor;
use IndianOpinion\SrcsetTruth\Backfill\Backfill;
use IndianOpinion\SrcsetTruth\Encoding\EncoderFactory;
use IndianOpinion\SrcsetTruth\Encoding\SiblingCreator;
use IndianOpinion\SrcsetTruth\Exception\AuditFailed;
use IndianOpinion\SrcsetTruth\Exception\InvalidSizesSpec;
use IndianOpinion\SrcsetTruth\Files\AttachmentFiles;
use IndianOpinion\SrcsetTruth\Files\ImageType;
use IndianOpinion\SrcsetTruth\Files\Siblings;
use IndianOpinion\SrcsetTruth\Files\SkipLedger;
use IndianOpinion\SrcsetTruth\Layout\SlotResolver;
use IndianOpinion\SrcsetTruth\Layout\TemplateResolver;
use IndianOpinion\SrcsetTruth\Layout\WidthMap;
use IndianOpinion\SrcsetTruth\Sizes\Compiler;

/**
 * Corrects sizes, serves WebP siblings and audits image weight.
 */
final class Command
{
    /**
     * Before-and-after image weight of one page of this site.
     *
     * ## OPTIONS
     *
     * <path-or-url>
     * : A path such as /news/ or a full URL on this site's own host.
     *
     * [--width=<px>]
     * : Viewport width in CSS px.
     * ---
     * default: 412
     * ---
     *
     * [--dpr=<n>]
     * : Device pixel ratio.
     * ---
     * default: 2
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function audit(array $args, array $assoc): void
    {
        $width = (float) ($assoc['width'] ?? Auditor::DEFAULT_WIDTH);
        $dpr = (float) ($assoc['dpr'] ?? Auditor::DEFAULT_DPR);
        $format = $assoc['format'] ?? 'table';
        try {
            $report = (new Auditor($width, $dpr))->run($args[0] ?? '/');
        } catch (AuditFailed $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }
        \WP_CLI::line(sprintf('Page: %s', $report['url']));
        $dprText = rtrim(rtrim(number_format($dpr, 3, '.', ''), '0'), '.');
        \WP_CLI::line(sprintf('Viewport: %d px at DPR %s (pick rule: smallest candidate with density >= DPR).', (int) $width, $dprText));
        $items = [];
        foreach ($report['images'] as $row) {
            $items[] = [
                '#' => $row['index'],
                'slot' => $row['slot'] ?? '-',
                'sizes' => $row['sizes'] ?? '-',
                'before' => self::pickText($row['before']),
                'after' => self::pickText($row['after']),
                'flags' => implode(' ', $row['flags'])
                    . ($row['missing_width'] !== null ? ' (need ' . $row['missing_width'] . 'w)' : '')
                    . ($row['unknown'] ? ' unknown' : ''),
            ];
        }
        if ($items !== []) {
            \WP_CLI\Utils\format_items('table', $items, ['#', 'slot', 'sizes', 'before', 'after', 'flags']);
        }
        $t = $report['totals'];
        \WP_CLI::line(sprintf('Images: %d (%d with known bytes)', $t['images'], $t['counted']));
        \WP_CLI::line(sprintf('Before: %s KiB', number_format($t['before_bytes'] / 1024, 1)));
        \WP_CLI::line(sprintf('After: %s KiB', number_format($t['after_bytes'] / 1024, 1)));
        \WP_CLI::line(sprintf('Saving: %s KiB (%s%%)', number_format($t['saving_kib'], 1), number_format($t['saving_percent'], 1)));
    }

    /**
     * @param array{url: string, width: ?int, bytes: ?int, format: string}|null $pick
     */
    private static function pickText(?array $pick): string
    {
        if ($pick === null) {
            return '-';
        }

        $width = $pick['width'] !== null ? $pick['width'] . 'w' : '1x';
        $bytes = $pick['bytes'] !== null ? number_format($pick['bytes'] / 1024, 1) . ' KiB' : '? KiB';

        return sprintf('%s %s %s', $width, $pick['format'], $bytes);
    }

    /**
     * Create missing and stale siblings, with no time budget.
     *
     * ## OPTIONS
     *
     * [--all]
     * : Every image attachment.
     *
     * [--attachment=<id>]
     * : One attachment.
     *
     * [--since=<date>]
     * : Attachments uploaded on or after this date (YYYY-MM-DD).
     *
     * [--limit=<n>]
     * : Stop after this many attachments.
     *
     * [--dry-run]
     * : Count what would be done; write nothing.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function generate(array $args, array $assoc): void
    {
        $ids = $this->selectIds($assoc);
        $dry = !empty($assoc['dry-run']);
        if (!$dry && EncoderFactory::available() === []) {
            \WP_CLI::error('No WebP encoder: install Imagick with WebP, or GD with WebP support.');

            return;
        }
        $counts = ['created' => 0, 'fresh' => 0, 'skipped' => 0, 'failed' => 0, 'missing' => 0, 'would-create' => 0];
        foreach ($ids as $id) {
            foreach (AttachmentFiles::served($id) as $file) {
                if ($dry) {
                    $state = is_file($file) ? Siblings::state($file) : 'gone';
                    $mime = ImageType::sniff($file);
                    $inScope = $mime !== null && in_array($mime, ImageType::SOURCE_TYPES, true);
                    if ($state !== 'fresh' && $state !== 'gone' && $inScope && SkipLedger::reason($file) === null) {
                        $counts['would-create']++;
                    }
                    continue;
                }
                $result = SiblingCreator::create($file);
                $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
                if ($result['status'] === 'failed') {
                    \WP_CLI::warning(sprintf('%s: %s', $file, (string) $result['reason']));
                }
            }
        }
        $summary = [];
        foreach ($counts as $key => $count) {
            if ($count > 0) {
                $summary[] = $key . ' ' . $count;
            }
        }
        \WP_CLI::success(sprintf('%d attachments: %s.', count($ids), $summary === [] ? 'nothing to do' : implode(', ', $summary)));
    }

    /**
     * @param array<string, string|bool> $assoc
     * @return list<int>
     */
    private function selectIds(array $assoc): array
    {
        $limit = isset($assoc['limit']) ? max(1, (int) $assoc['limit']) : PHP_INT_MAX;
        if (isset($assoc['attachment'])) {
            $id = (int) $assoc['attachment'];
            if ($id <= 0) {
                \WP_CLI::error('--attachment needs a positive ID.');
            }

            return [$id];
        }
        $source = Backfill::source();
        $ids = [];
        if (isset($assoc['since']) && is_string($assoc['since'])) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $assoc['since']) !== 1) {
                \WP_CLI::error('--since takes a date such as 2025-01-31.');
            }
            $offset = 0;
            while (count($ids) < $limit) {
                $batch = $source->idsSince($assoc['since'], 200, $offset);
                if ($batch === []) {
                    break;
                }
                $ids = array_merge($ids, $batch);
                $offset += count($batch);
            }

            return array_slice($ids, 0, min($limit, count($ids)));
        }
        if (empty($assoc['all'])) {
            \WP_CLI::error('Say which attachments: --all, --attachment=<id> or --since=<date>.');
        }
        $below = null;
        while (count($ids) < $limit) {
            $batch = $source->idsBelow($below, 200);
            if ($batch === []) {
                break;
            }
            $ids = array_merge($ids, $batch);
            $below = (int) end($batch);
        }

        return array_slice($ids, 0, min($limit, count($ids)));
    }

    /**
     * Encoder, coverage, cursor, last backfill and lock state.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $coverage = ['originals' => 0, 'fresh' => 0, 'stale' => 0, 'missing' => 0];
        $source = Backfill::source();
        $below = null;
        while (true) {
            $batch = $source->idsBelow($below, 200);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $id) {
                foreach (AttachmentFiles::served($id) as $file) {
                    if (!is_file($file) || !Siblings::hasSourceExtension($file)) {
                        continue;
                    }
                    $coverage['originals']++;
                    $coverage[Siblings::state($file)]++;
                }
            }
            $below = (int) end($batch);
        }
        $ledger = SkipLedger::counts();
        $notBeneficial = $ledger['not-beneficial'] ?? 0;
        $failed = array_sum($ledger) - $notBeneficial;
        $state = Backfill::state();
        \WP_CLI::line('Encoder: ' . EncoderFactory::describe());
        \WP_CLI::line(sprintf(
            'Coverage: %d originals in scope, %d fresh siblings, %d stale, %d not beneficial, %d failed',
            $coverage['originals'],
            $coverage['fresh'],
            $coverage['stale'],
            $notBeneficial,
            $failed
        ));
        \WP_CLI::line('Backfill: ' . (Backfill::enabled() ? 'on' : 'off'));
        \WP_CLI::line('Cursor: ' . ($state['cursor'] === null ? 'top (newest)' : 'below ID ' . $state['cursor']));
        $last = 'never';
        if ($state['last_run'] > 0) {
            $last = gmdate('Y-m-d H:i:s', $state['last_run'])
                . sprintf(' UTC (%d created, %d failed, %.1fs)', $state['last_done'], $state['last_failed'], $state['last_seconds']);
        }
        \WP_CLI::line('Last backfill: ' . $last);
        \WP_CLI::line('Last full pass: ' . ($state['last_full_pass'] > 0 ? gmdate('Y-m-d H:i:s', $state['last_full_pass']) . ' UTC' : 'not yet'));
        \WP_CLI::line('Lock: ' . Backfill::lockState());
    }

    /**
     * Print the width map and slot rules after filters.
     *
     * ## OPTIONS
     *
     * [--template=<key>]
     * : Resolve the map as this template key sees it.
     * ---
     * default: default
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function map(array $args, array $assoc): void
    {
        $template = $assoc['template'] ?? 'default';
        $map = WidthMap::forTemplate($template);
        \WP_CLI::line(sprintf('Template key: %s (lookup order: %s)', $template, implode(', ', TemplateResolver::chain($template))));
        if ($map === []) {
            \WP_CLI::line('Width map: empty. Nothing is corrected until a map is supplied (filter or preset).');
        }
        $items = [];
        $errors = 0;
        foreach ($map as $key => $slots) {
            foreach ($slots as $slot => $spec) {
                try {
                    if (!is_string($spec) && !is_array($spec)) {
                        throw new InvalidSizesSpec('A sizes spec must be a string or a breakpoint list.');
                    }
                    $compiled = Compiler::compile($spec);
                    $error = '';
                } catch (InvalidSizesSpec $e) {
                    $compiled = '';
                    $error = $e->getMessage();
                    $errors++;
                }
                $items[] = ['template' => $key, 'slot' => $slot, 'sizes' => $compiled, 'error' => $error];
            }
        }
        if ($items !== []) {
            \WP_CLI\Utils\format_items('table', $items, ['template', 'slot', 'sizes', 'error']);
        }
        $rules = SlotResolver::rules();
        \WP_CLI::line(sprintf('Slot rules: %d', count($rules)));
        foreach ($rules as $i => $rule) {
            \WP_CLI::line(sprintf('  %d. %s', $i + 1, (string) wp_json_encode($rule, JSON_UNESCAPED_SLASHES)));
        }
        if ($errors > 0) {
            \WP_CLI::warning(sprintf('%d invalid spec(s). Those slots keep the core value.', $errors));
        }
    }

    /**
     * Remove siblings (files ending ".jpg.webp", ".png.webp", ".gif.webp")
     * next to known attachment files. Never touches originals.
     *
     * ## OPTIONS
     *
     * --yes
     * : Confirm.
     *
     * [--attachment=<id>]
     * : Only this attachment.
     *
     * @subcommand remove-siblings
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function removeSiblings(array $args, array $assoc): void
    {
        if (empty($assoc['yes'])) {
            \WP_CLI::error('This deletes sibling files. Purge page and CDN caches first, then run again with --yes.');

            return;
        }
        $ids = isset($assoc['attachment']) ? [(int) $assoc['attachment']] : $this->selectIds(['all' => true]);
        $removed = 0;
        foreach ($ids as $id) {
            foreach (AttachmentFiles::all($id) as $file) {
                if (SiblingCreator::removeSibling($file)) {
                    $removed++;
                }
                SkipLedger::forget($file);
            }
        }
        \WP_CLI::success(sprintf('Removed %d sibling file(s) from %d attachment(s).', $removed, count($ids)));
    }
}

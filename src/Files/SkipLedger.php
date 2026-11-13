<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Files;

/**
 * Remembers originals that were not beneficial or failed, so neither the
 * upload hook nor the backfill retries them until the original changes
 * (PRD cases 31, 37, and section 6).
 *
 * Sibling records themselves are not stored: they are derived from disk.
 * This ledger only holds negative results, keyed by the path relative to
 * uploads, with the source modification time and size. A changed original
 * no longer matches its entry, so it is evaluated again. One option, not
 * autoloaded, capped so it cannot grow without bound.
 *
 * Bound: CAP entries of about 140 serialised bytes each, so the option stays
 * near 1.4 MB at most; past the cap the oldest records go first. Writes
 * re-read the stored option first, so a long backfill or WP-CLI run does
 * not overwrite records another process added since it started. Two
 * processes writing in the same instant can still lose one record (there is
 * no row lock); a lost or dropped record only means that file is tried
 * again once, never that a sibling is served wrongly.
 */
final class SkipLedger
{
    public const OPTION = 'srcset_truth_skips';

    public const CAP = 10000;

    /** @var array<string, array{m: int, s: int, r: string, t: int}>|null */
    private static ?array $entries = null;

    /**
     * The recorded reason for this original, or null when there is no
     * matching record.
     */
    public static function reason(string $path): ?string
    {
        $entries = self::load();
        $key = Uploads::relative($path);
        if (!isset($entries[$key])) {
            return null;
        }
        clearstatcache(true, $path);
        $mtime = is_file($path) ? (int) filemtime($path) : -1;
        $size = is_file($path) ? (int) filesize($path) : -1;
        if ($entries[$key]['m'] !== $mtime || $entries[$key]['s'] !== $size) {
            return null;
        }

        return $entries[$key]['r'];
    }

    public static function record(string $path, string $reason): void
    {
        $entries = self::load(true);
        clearstatcache(true, $path);
        $entries[Uploads::relative($path)] = [
            'm' => is_file($path) ? (int) filemtime($path) : -1,
            's' => is_file($path) ? (int) filesize($path) : -1,
            'r' => $reason,
            't' => time(),
        ];
        if (count($entries) > self::CAP) {
            uasort($entries, static fn (array $a, array $b): int => $a['t'] <=> $b['t']);
            $entries = array_slice($entries, count($entries) - self::CAP, null, true);
        }
        self::save($entries);
    }

    public static function forget(string $path): void
    {
        $key = Uploads::relative($path);
        if (!isset(self::load()[$key])) {
            return;
        }
        $entries = self::load(true);
        if (isset($entries[$key])) {
            unset($entries[$key]);
            self::save($entries);
        }
    }

    /**
     * @return array<string, int> reason => count
     */
    public static function counts(): array
    {
        $counts = [];
        foreach (self::load() as $entry) {
            $counts[$entry['r']] = ($counts[$entry['r']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array<string, array{m: int, s: int, r: string, t: int}>
     */
    private static function load(bool $fresh = false): array
    {
        if ($fresh && function_exists('wp_cache_delete')) {
            // Non-autoloaded options are cached under their own key.
            wp_cache_delete(self::OPTION, 'options');
        }
        if (self::$entries === null || $fresh) {
            $stored = get_option(self::OPTION, []);
            $clean = [];
            foreach (is_array($stored) ? $stored : [] as $key => $entry) {
                if (is_string($key) && is_array($entry) && isset($entry['m'], $entry['s'], $entry['r'], $entry['t'])) {
                    $clean[$key] = ['m' => (int) $entry['m'], 's' => (int) $entry['s'], 'r' => (string) $entry['r'], 't' => (int) $entry['t']];
                }
            }
            self::$entries = $clean;
        }

        return self::$entries;
    }

    /**
     * @param array<string, array{m: int, s: int, r: string, t: int}> $entries
     */
    private static function save(array $entries): void
    {
        self::$entries = $entries;
        update_option(self::OPTION, $entries, false);
    }

    public static function reset(): void
    {
        self::$entries = null;
    }
}

<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Files;

/**
 * Sibling naming and freshness (PRD terms, cases 35, 36, 53, 54).
 *
 * A sibling is the original path with ".webp" appended, so photo.jpg and
 * photo.png never collide (lesson 14). Freshness checks are memoised for the
 * request: each path is stat-checked at most once.
 */
final class Siblings
{
    public const SOURCE_EXTENSIONS = ['jpg', 'jpeg', 'jpe', 'png', 'gif'];

    /** @var array<string, bool> */
    private static array $fresh = [];

    /** Number of real file-system checks made, for tests and the budget. */
    public static int $stats = 0;

    public static function path(string $original): string
    {
        return $original . '.webp';
    }

    /**
     * Sibling URL: ".webp" goes before any query string.
     */
    public static function url(string $originalUrl): string
    {
        $cut = strcspn($originalUrl, '?#');

        return substr($originalUrl, 0, $cut) . '.webp' . substr($originalUrl, $cut);
    }

    /**
     * The original URL behind a sibling URL, or null when it is not one.
     */
    public static function originalUrl(string $url): ?string
    {
        $cut = strcspn($url, '?#');
        $path = substr($url, 0, $cut);
        if (preg_match('/\.(jpe?g|jpe|png|gif)\.webp$/i', $path) !== 1) {
            return null;
        }

        return substr($path, 0, -5) . substr($url, $cut);
    }

    public static function hasSourceExtension(string $pathOrUrl): bool
    {
        $path = substr($pathOrUrl, 0, strcspn($pathOrUrl, '?#'));
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::SOURCE_EXTENSIONS, true);
    }

    /**
     * True when a fresh sibling exists for the original. A stale sibling
     * (older than its original) counts as missing.
     */
    public static function isFresh(string $original): bool
    {
        if (array_key_exists($original, self::$fresh)) {
            return self::$fresh[$original];
        }
        $answer = apply_filters('srcset_truth_has_sibling', null, $original);
        if (is_bool($answer)) {
            return self::$fresh[$original] = $answer;
        }
        self::$stats++;

        return self::$fresh[$original] = self::checkDisk($original);
    }

    /**
     * Uncached state of one original: missing, fresh or stale.
     */
    public static function state(string $original): string
    {
        clearstatcache(true, $original);
        clearstatcache(true, self::path($original));
        $sibling = self::path($original);
        if (!is_file($sibling)) {
            return 'missing';
        }

        return self::checkDisk($original) ? 'fresh' : 'stale';
    }

    private static function checkDisk(string $original): bool
    {
        $sibling = self::path($original);
        if (!is_file($original) || !is_file($sibling)) {
            return false;
        }
        $originalTime = filemtime($original);
        $siblingTime = filemtime($sibling);

        return $originalTime !== false && $siblingTime !== false && $siblingTime >= $originalTime;
    }

    public static function forget(string $original): void
    {
        unset(self::$fresh[$original]);
    }

    public static function reset(): void
    {
        self::$fresh = [];
        self::$stats = 0;
    }
}

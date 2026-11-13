<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth;

/**
 * Opt-in debug log (PRD 11). Writes through error_log only when the debug
 * setting is on and WP_DEBUG_LOG is set. Each key is written once per request.
 */
final class Logger
{
    /** @var array<string, true> */
    private static array $seen = [];

    /** @var (callable(string): void)|null */
    private static $sink = null;

    /**
     * Replace the writer (tests capture lines with this).
     *
     * @param (callable(string): void)|null $sink
     */
    public static function setSink(?callable $sink): void
    {
        self::$sink = $sink;
    }

    public static function enabled(): bool
    {
        return Settings::bool('debug') && defined('WP_DEBUG_LOG') && constant('WP_DEBUG_LOG');
    }

    /**
     * Log once per request per key.
     */
    public static function once(string $key, string $message): void
    {
        if (isset(self::$seen[$key])) {
            return;
        }
        self::$seen[$key] = true;
        self::log($message);
    }

    public static function log(string $message): void
    {
        if (!self::enabled()) {
            return;
        }
        $line = '[srcset-truth] ' . (string) preg_replace('/[\r\n]+/', ' ', $message);
        if (self::$sink !== null) {
            (self::$sink)($line);

            return;
        }
        error_log($line);
    }

    public static function reset(): void
    {
        self::$seen = [];
    }
}

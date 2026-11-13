<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth;

/**
 * Thin wrapper over header() so tests can see what was sent.
 */
final class Response
{
    /** @var list<string> */
    public static array $sent = [];

    public static function header(string $line): void
    {
        self::$sent[] = $line;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header($line);
        }
    }

    public static function privateNoStore(): void
    {
        self::header('Cache-Control: private, no-store, max-age=0');
    }

    public static function reset(): void
    {
        self::$sent = [];
    }
}

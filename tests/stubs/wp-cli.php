<?php

/**
 * A minimal WP_CLI stand-in that records output. error() throws, as the
 * real one exits with status 1.
 */

declare(strict_types=1);

namespace {
    final class WP_CLI_Halt extends \RuntimeException
    {
    }

    class WP_CLI
    {
        /** @var list<string> */
        public static array $lines = [];
        /** @var array<string, mixed> */
        public static array $commands = [];

        public static function line(string $message = ''): void
        {
            self::$lines[] = $message;
        }

        public static function success(string $message): void
        {
            self::$lines[] = 'Success: ' . $message;
        }

        public static function warning(string $message): void
        {
            self::$lines[] = 'Warning: ' . $message;
        }

        public static function error(string $message): void
        {
            self::$lines[] = 'Error: ' . $message;
            throw new WP_CLI_Halt($message, 1);
        }

        public static function add_command(string $name, mixed $callable): void
        {
            self::$commands[$name] = $callable;
        }

        public static function reset(): void
        {
            self::$lines = [];
            self::$commands = [];
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        foreach ($items as $item) {
            $cells = [];
            foreach ($fields as $field) {
                $cells[] = (string) ($item[$field] ?? '');
            }
            \WP_CLI::line(implode(' | ', $cells));
        }
    }
}

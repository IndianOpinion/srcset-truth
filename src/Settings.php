<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth;

/**
 * The one settings option (PRD 5.7 and 7). Small and autoloaded.
 *
 * @phpstan-type SettingsShape array{webp: bool, quality: int, mode: string, lcp: bool, preset: string, override: bool, backfill: bool, debug: bool}
 */
final class Settings
{
    public const OPTION = 'srcset_truth_settings';

    public const MODES = ['filter', 'buffer'];

    public const PRESETS = ['none', 'grid'];

    /** @var array<string, bool|int|string>|null */
    private static ?array $cache = null;

    /**
     * @return SettingsShape
     */
    public static function defaults(): array
    {
        return [
            'webp' => true,
            'quality' => 82,
            'mode' => 'filter',
            'lcp' => false,
            'preset' => 'none',
            'override' => false,
            'backfill' => true,
            'debug' => false,
        ];
    }

    /**
     * @return SettingsShape
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = get_option(self::OPTION, []);
            self::$cache = self::normalise(is_array($stored) ? $stored : []);
        }

        /** @var SettingsShape */
        return self::$cache;
    }

    public static function get(string $key): bool|int|string
    {
        $all = self::all();

        return $all[$key] ?? self::defaults()[$key] ?? false;
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Merge stored values over defaults, dropping anything out of type or range.
     *
     * @param array<mixed> $stored
     * @return SettingsShape
     */
    public static function normalise(array $stored): array
    {
        $clean = self::defaults();
        foreach (['webp', 'lcp', 'override', 'backfill', 'debug'] as $key) {
            if (array_key_exists($key, $stored)) {
                $clean[$key] = (bool) $stored[$key];
            }
        }
        if (isset($stored['quality']) && self::validQuality($stored['quality'])) {
            $clean['quality'] = (int) $stored['quality'];
        }
        if (isset($stored['mode']) && in_array($stored['mode'], self::MODES, true)) {
            $clean['mode'] = $stored['mode'];
        }
        if (isset($stored['preset']) && in_array($stored['preset'], self::PRESETS, true)) {
            $clean['preset'] = $stored['preset'];
        }

        return $clean;
    }

    /**
     * Settings API sanitisation callback. Rejects out-of-range values with a
     * settings error and keeps the previous value for that field.
     *
     * @param mixed $input
     * @return SettingsShape
     */
    public static function sanitize($input): array
    {
        $previous = self::all();
        if (!is_array($input)) {
            return $previous;
        }
        $clean = $previous;
        foreach (['webp', 'lcp', 'override', 'backfill', 'debug'] as $key) {
            $clean[$key] = !empty($input[$key]);
        }
        if (array_key_exists('quality', $input)) {
            if (self::validQuality($input['quality'])) {
                $clean['quality'] = (int) $input['quality'];
            } else {
                self::error('quality', __('WebP quality must be a whole number from 1 to 100. The previous value was kept.', 'srcset-truth'));
            }
        }
        if (array_key_exists('mode', $input)) {
            if (in_array($input['mode'], self::MODES, true)) {
                $clean['mode'] = (string) $input['mode'];
            } else {
                self::error('mode', __('Mode must be "filter" or "buffer". The previous value was kept.', 'srcset-truth'));
            }
        }
        if (array_key_exists('preset', $input)) {
            if (in_array($input['preset'], self::PRESETS, true)) {
                $clean['preset'] = (string) $input['preset'];
            } else {
                self::error('preset', __('Preset must be "none" or "grid". The previous value was kept.', 'srcset-truth'));
            }
        }
        self::flush();

        return $clean;
    }

    private static function validQuality(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= 100;
        }
        if (is_string($value) && preg_match('/^\d{1,3}$/', trim($value)) === 1) {
            $int = (int) $value;

            return $int >= 1 && $int <= 100;
        }

        return false;
    }

    private static function error(string $code, string $message): void
    {
        if (function_exists('add_settings_error')) {
            add_settings_error(self::OPTION, 'srcset-truth-' . $code, $message);
        }
    }
}

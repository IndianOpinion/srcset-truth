<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Sizes;

/**
 * Recognises the sizes value WordPress builds when nothing filters it:
 * "(max-width: Wpx) 100vw, Wpx", optionally preceded by "auto, ".
 */
final class CoreDefault
{
    private const PATTERN = '/^\s*(auto\s*,\s*)?\(\s*max-width\s*:\s*(\d+)\s*px\s*\)\s*100vw\s*,\s*(\d+)\s*px\s*$/i';

    /**
     * @return array{width: int, auto: bool}|null Null when the value is not the core default.
     */
    public static function parse(string $sizes): ?array
    {
        if (preg_match(self::PATTERN, $sizes, $match) !== 1) {
            return null;
        }
        if ($match[2] !== $match[3]) {
            return null;
        }

        return ['width' => (int) $match[2], 'auto' => $match[1] !== ''];
    }

    public static function is(string $sizes): bool
    {
        return self::parse($sizes) !== null;
    }

    public static function build(int $width): string
    {
        return sprintf('(max-width: %1$dpx) 100vw, %1$dpx', $width);
    }

    public static function hasAuto(string $sizes): bool
    {
        return preg_match('/^\s*auto\s*(,|$)/i', $sizes) === 1;
    }

    public static function stripAuto(string $sizes): string
    {
        return (string) preg_replace('/^\s*auto\s*(,\s*|$)/i', '', $sizes);
    }
}

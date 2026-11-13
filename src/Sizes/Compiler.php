<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Sizes;

use IndianOpinion\SrcsetTruth\Exception\InvalidSizesSpec;

/**
 * Turns a sizes spec into a validated sizes string (PRD cases 7, 20, 22).
 *
 * A spec is either a ready string or a list of [max_width_px|null, length]
 * pairs. The result never carries "auto": the caller adds it when the tag
 * stays lazy (case 6).
 */
final class Compiler
{
    /**
     * @param array<mixed>|string $spec
     * @throws InvalidSizesSpec
     */
    public static function compile(array|string $spec, ?int $imageWidth = null): string
    {
        $sizes = is_string($spec) ? self::fromString($spec) : self::fromPairs($spec);
        $reason = Grammar::check($sizes);
        if ($reason !== null) {
            throw new InvalidSizesSpec(sprintf('Invalid sizes spec "%s": %s.', self::preview($sizes), $reason));
        }
        if ($imageWidth !== null && $imageWidth > 0) {
            $sizes = self::cap($sizes, $imageWidth);
        }

        return $sizes;
    }

    private static function fromString(string $spec): string
    {
        $spec = trim((string) preg_replace('/\s+/', ' ', $spec));

        return CoreDefault::stripAuto($spec);
    }

    /**
     * @param array<mixed> $pairs
     */
    private static function fromPairs(array $pairs): string
    {
        if ($pairs === [] || !array_is_list($pairs)) {
            throw new InvalidSizesSpec('A breakpoint list must be a non-empty list of [max_width, length] pairs.');
        }
        $byBreakpoint = [];
        $default = null;
        $nullCount = 0;
        foreach ($pairs as $pair) {
            if (!is_array($pair) || count($pair) !== 2 || !array_key_exists(0, $pair) || !array_key_exists(1, $pair)) {
                throw new InvalidSizesSpec('Each breakpoint pair must be [int|null max_width_px, string length].');
            }
            [$breakpoint, $length] = $pair;
            if (!is_string($length) || !Grammar::isLength($length)) {
                throw new InvalidSizesSpec(sprintf('Invalid length in breakpoint pair: "%s".', self::preview(is_scalar($length) ? (string) $length : gettype($length))));
            }
            if ($breakpoint === null) {
                $nullCount++;
                $default = trim($length);
                continue;
            }
            if (!is_int($breakpoint) || $breakpoint <= 0) {
                throw new InvalidSizesSpec('A breakpoint must be a positive integer number of px, or null for the default.');
            }
            // Duplicate breakpoints: the last entry wins.
            $byBreakpoint[$breakpoint] = trim($length);
        }
        if ($nullCount !== 1 || $default === null) {
            throw new InvalidSizesSpec('A breakpoint list needs exactly one pair with a null breakpoint (the default length).');
        }
        ksort($byBreakpoint, SORT_NUMERIC);
        $parts = [];
        foreach ($byBreakpoint as $breakpoint => $length) {
            $parts[] = sprintf('(max-width: %dpx) %s', $breakpoint, $length);
        }
        $parts[] = $default;

        return implode(', ', $parts);
    }

    /**
     * Case 7: the final length never declares more than the image is wide.
     */
    public static function cap(string $sizes, int $imageWidth): string
    {
        $entries = Grammar::splitTopLevel($sizes);
        if ($entries === null || $entries === []) {
            return $sizes;
        }
        $lastIndex = count($entries) - 1;
        $last = $entries[$lastIndex];
        if (preg_match('/^(\d+(?:\.\d+)?)px$/i', $last, $match) === 1) {
            if ((float) $match[1] <= $imageWidth) {
                return $sizes;
            }
            $entries[$lastIndex] = $imageWidth . 'px';
        } elseif (preg_match('/^min\(.*,\s*(\d+)px\)$/i', $last, $match) === 1 && (int) $match[1] <= $imageWidth) {
            return $sizes;
        } else {
            $entries[$lastIndex] = sprintf('min(%s, %dpx)', $last, $imageWidth);
        }

        return implode(', ', $entries);
    }

    private static function preview(string $value): string
    {
        $value = (string) preg_replace('/[^\x20-\x7e]/', '?', $value);

        return strlen($value) > 120 ? substr($value, 0, 117) . '...' : $value;
    }
}

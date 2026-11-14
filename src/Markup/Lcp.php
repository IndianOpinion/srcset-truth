<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Markup;

use IndianOpinion\SrcsetTruth\Files\PathMapper;

/**
 * Picks the image most likely to be the Largest Contentful Paint (PRD 57).
 * Width alone is not enough: a wide logo passes a width test (lesson 9).
 */
final class Lcp
{
    /**
     * @return array{min_width: int, min_ratio: float, max_ratio: float, markers: list<string>}
     */
    public static function rules(): array
    {
        $defaults = ['min_width' => 600, 'min_ratio' => 1.2, 'max_ratio' => 2.2, 'markers' => ['logo', 'avatar', 'gravatar', 'icon']];
        $rules = apply_filters('srcset_truth_lcp_rules', $defaults);
        if (!is_array($rules)) {
            return $defaults;
        }
        $markers = isset($rules['markers']) && is_array($rules['markers']) ? array_values(array_filter($rules['markers'], 'is_string')) : $defaults['markers'];

        return [
            'min_width' => isset($rules['min_width']) && is_numeric($rules['min_width']) ? (int) $rules['min_width'] : $defaults['min_width'],
            'min_ratio' => isset($rules['min_ratio']) && is_numeric($rules['min_ratio']) ? (float) $rules['min_ratio'] : $defaults['min_ratio'],
            'max_ratio' => isset($rules['max_ratio']) && is_numeric($rules['max_ratio']) ? (float) $rules['max_ratio'] : $defaults['max_ratio'],
            'markers' => $markers,
        ];
    }

    /**
     * @param array<string, string|true> $attrs
     */
    public static function qualifies(array $attrs, int $index): bool
    {
        $forced = apply_filters('srcset_truth_lcp_candidate', null, $attrs, $index);
        if (is_bool($forced)) {
            return $forced;
        }
        $rules = self::rules();
        [$width, $height] = self::dimensions($attrs);
        if ($width === null || $height === null || $height <= 0 || $width < $rules['min_width']) {
            return false;
        }
        $ratio = $width / $height;
        if ($ratio < $rules['min_ratio'] || $ratio > $rules['max_ratio']) {
            return false;
        }
        $haystack = strtolower(implode(' ', array_map(
            static fn ($key): string => is_string($attrs[$key] ?? null) ? (string) $attrs[$key] : '',
            ['class', 'id', 'alt', 'src']
        )));
        foreach ($rules['markers'] as $marker) {
            if ($marker !== '' && str_contains($haystack, strtolower($marker))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Width and height from attributes, falling back to the local file.
     *
     * @param array<string, string|true> $attrs
     * @return array{0: ?int, 1: ?int}
     */
    public static function dimensions(array $attrs): array
    {
        $width = self::intAttr($attrs['width'] ?? null);
        $height = self::intAttr($attrs['height'] ?? null);
        if ($width !== null && $height !== null) {
            return [$width, $height];
        }
        $file = self::fileDimensions($attrs);
        if ($file === null) {
            return [$width, $height];
        }
        if ($width === null && $height === null) {
            return $file;
        }
        if ($width === null) {
            return [(int) round((int) $height * $file[0] / max(1, $file[1])), $height];
        }

        return [$width, (int) round($width * $file[1] / max(1, $file[0]))];
    }

    /**
     * Real dimensions of the local file behind src, or null when remote.
     *
     * @param array<string, string|true> $attrs
     * @return array{0: int, 1: int}|null
     */
    public static function fileDimensions(array $attrs): ?array
    {
        $src = is_string($attrs['src'] ?? null) ? $attrs['src'] : '';
        if ($src === '') {
            return null;
        }
        $path = PathMapper::urlToPath($src);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $info = getimagesize($path);
        if (!is_array($info) || $info[0] <= 0 || $info[1] <= 0) {
            return null;
        }

        return [(int) $info[0], (int) $info[1]];
    }

    private static function intAttr(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^\s*(\d+)(?:px)?\s*$/i', $value, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}

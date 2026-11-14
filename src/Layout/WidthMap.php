<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Layout;

use IndianOpinion\SrcsetTruth\Settings;

/**
 * The width map: template key => slot => sizes spec (PRD 18, 19).
 */
final class WidthMap
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $resolved = [];

    /**
     * The map after the preset and the srcset_truth_width_map filter, with
     * "*" folded into "default".
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forTemplate(string $template): array
    {
        if (isset(self::$resolved[$template])) {
            return self::$resolved[$template];
        }
        $preset = Presets::widthMap((string) Settings::get('preset'));
        $map = apply_filters('srcset_truth_width_map', $preset, $template);
        self::$resolved[$template] = self::normalise(is_array($map) ? $map : []);

        return self::$resolved[$template];
    }

    /**
     * @param array<mixed> $map
     * @return array<string, array<string, mixed>>
     */
    public static function normalise(array $map): array
    {
        // "*" is an alias for "default"; an explicit "default" entry wins.
        if (isset($map['*']) && is_array($map['*'])) {
            $explicit = isset($map['default']) && is_array($map['default']) ? $map['default'] : [];
            $map['default'] = array_merge($map['*'], $explicit);
            unset($map['*']);
        }
        $clean = [];
        foreach ($map as $key => $slots) {
            if (!is_string($key) || !is_array($slots)) {
                continue;
            }
            foreach ($slots as $slot => $spec) {
                if (is_string($slot)) {
                    $clean[$key][$slot] = $spec;
                }
            }
        }

        return $clean;
    }

    /**
     * First hit along the template chain wins.
     *
     * @return array{spec: mixed, template: string}|null
     */
    public static function lookup(string $template, string $slot): ?array
    {
        $map = self::forTemplate($template);
        foreach (TemplateResolver::chain($template) as $key) {
            if (isset($map[$key]) && array_key_exists($slot, $map[$key])) {
                return ['spec' => $map[$key][$slot], 'template' => $key];
            }
        }

        return null;
    }

    /**
     * True when the slot name appears under any template key.
     */
    public static function knowsSlot(string $template, string $slot): bool
    {
        foreach (self::forTemplate($template) as $slots) {
            if (array_key_exists($slot, $slots)) {
                return true;
            }
        }

        return false;
    }

    public static function reset(): void
    {
        self::$resolved = [];
    }
}

<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Layout;

use IndianOpinion\SrcsetTruth\Logger;
use IndianOpinion\SrcsetTruth\Settings;

/**
 * Slot resolution (PRD 15): explicit stack, then rules, then the filter.
 */
final class SlotResolver
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $rules = null;

    /**
     * @param array<string, mixed> $context
     */
    public static function resolve(array $context): ?string
    {
        $explicit = SlotStack::current();
        if ($explicit !== null) {
            return $explicit;
        }
        foreach (self::rules() as $rule) {
            if (self::matches($rule, $context)) {
                return (string) $rule['slot'];
            }
        }
        $filtered = apply_filters('srcset_truth_slot', null, $context);
        if (is_string($filtered) && $filtered !== '') {
            if (SlotStack::isValid($filtered)) {
                return $filtered;
            }
            Logger::once('bad-slot-filter:' . $filtered, sprintf('srcset_truth_slot returned an invalid slot name "%s"; ignored.', $filtered));
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        $rules = apply_filters('srcset_truth_slot_rules', Presets::slotRules((string) Settings::get('preset')));
        $clean = [];
        foreach (is_array($rules) ? $rules : [] as $index => $rule) {
            if (!is_array($rule) || !isset($rule['slot']) || !is_string($rule['slot']) || !SlotStack::isValid($rule['slot'])) {
                Logger::once('bad-rule:' . $index, sprintf('Slot rule %s has no valid "slot" key; skipped.', (string) $index));
                continue;
            }
            /** @var array<string, mixed> $rule */
            $clean[] = $rule;
        }
        self::$rules = $clean;

        return $clean;
    }

    /**
     * All conditions in one rule must hold. A rule with only "slot" matches
     * everything.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $context
     */
    public static function matches(array $rule, array $context): bool
    {
        if (array_key_exists('size', $rule)) {
            $sizes = self::stringList($rule['size']);
            $size = $context['size'] ?? null;
            if (!is_string($size) || !in_array($size, $sizes, true)) {
                return false;
            }
        }
        if (array_key_exists('class', $rule)) {
            $wanted = self::stringList($rule['class']);
            $present = preg_split('/\s+/', trim((string) ($context['class'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            if ($present === false || array_intersect($wanted, $present) === []) {
                return false;
            }
        }
        if (array_key_exists('source', $rule)) {
            if (!in_array($context['source'] ?? '', self::stringList($rule['source']), true)) {
                return false;
            }
        }
        $width = (int) ($context['image_width'] ?? 0);
        if (array_key_exists('min_width', $rule)) {
            if ($width <= 0 || $width < (int) $rule['min_width']) {
                return false;
            }
        }
        if (array_key_exists('max_width', $rule)) {
            if ($width <= 0 || $width > (int) $rule['max_width']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    public static function reset(): void
    {
        self::$rules = null;
    }
}

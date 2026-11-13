<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Correction;

use IndianOpinion\SrcsetTruth\Exception\InvalidSizesSpec;
use IndianOpinion\SrcsetTruth\Layout\SlotResolver;
use IndianOpinion\SrcsetTruth\Layout\WidthMap;
use IndianOpinion\SrcsetTruth\Logger;
use IndianOpinion\SrcsetTruth\Settings;
use IndianOpinion\SrcsetTruth\Sizes\Compiler;
use IndianOpinion\SrcsetTruth\Sizes\CoreDefault;
use IndianOpinion\SrcsetTruth\Sizes\Grammar;

/**
 * The one classifier every path shares (PRD 4.1, case 10).
 *
 * Given the sizes value a tag carries and its context, returns the value the
 * tag should carry. It never makes anything up: with no slot or no map entry
 * the core value stays (case 4, lesson 8).
 */
final class Corrector
{
    /** @var array<string, string|false> compiled sizes per template|slot|width; false when invalid */
    private static array $compiled = [];

    /** @var array<string, true> values this plugin produced in this request (without "auto") */
    private static array $produced = [];

    /** @var array<string, int> */
    private static array $stats = ['corrected' => 0, 'unmapped' => 0, 'authored' => 0, 'invalid-spec' => 0, 'compiles' => 0];

    /**
     * @param array<string, mixed> $context template, attachment_id, size, image_width, src, class, source
     * @return array{sizes: string, changed: bool, status: string, context: array<string, mixed>}
     */
    public static function correct(string $sizes, array $context, bool $finalLazy, bool $hasSrcset): array
    {
        $result = static function (string $value, string $status) use ($sizes, &$context): array {
            return ['sizes' => $value, 'changed' => $value !== $sizes, 'status' => $status, 'context' => $context];
        };
        if (!$hasSrcset || trim($sizes) === '') {
            return $result($sizes, 'none');
        }
        $hadAuto = CoreDefault::hasAuto($sizes);
        $bare = trim(CoreDefault::stripAuto($sizes));
        if (isset(self::$produced[$bare])) {
            // Case 8: already ours. Only the auto rule can still apply.
            return $result(self::withAuto($bare, $hadAuto && $finalLazy, $sizes), 'corrected');
        }
        $default = CoreDefault::parse($sizes);
        $eligible = $default !== null;
        $should = $eligible || Settings::bool('override');
        $should = (bool) apply_filters('srcset_truth_should_correct', $should, $sizes, $context);
        if (!$should) {
            if (!$eligible) {
                self::$stats['authored']++;
            }

            return $result(self::autoFix($sizes, $finalLazy), $eligible ? 'skipped' : 'authored');
        }
        $width = $default['width'] ?? (int) ($context['image_width'] ?? 0);
        if ($width > 0) {
            $context['image_width'] = $width;
        }
        $template = (string) ($context['template'] ?? 'default');
        $slot = SlotResolver::resolve($context);
        $context['slot'] = $slot;
        if ($slot === null) {
            self::$stats['unmapped']++;

            return $result(self::autoFix($sizes, $finalLazy), 'unmapped');
        }
        $hit = WidthMap::lookup($template, $slot);
        if ($hit === null) {
            self::$stats['unmapped']++;
            if (!WidthMap::knowsSlot($template, $slot)) {
                Logger::once('unknown-slot:' . $slot, sprintf('Unknown slot "%s" (no width map entry under any template); image left unmapped.', $slot));
            }

            return $result(self::autoFix($sizes, $finalLazy), 'unmapped');
        }
        $compiled = self::compileCached($template, $slot, $width, $hit['spec']);
        if ($compiled === false) {
            self::$stats['invalid-spec']++;

            return $result(self::autoFix($sizes, $finalLazy), 'invalid-spec');
        }
        $filtered = apply_filters('srcset_truth_sizes', $compiled, $context);
        if (!is_string($filtered) || !Grammar::isValid($filtered)) {
            Logger::once(
                'invalid-filter:' . $template . ':' . $slot,
                sprintf('srcset_truth_sizes returned an invalid value for template "%s", slot "%s"; core value kept.', $template, $slot)
            );
            self::$stats['invalid-spec']++;

            return $result(self::autoFix($sizes, $finalLazy), 'invalid-spec');
        }
        $filtered = trim(CoreDefault::stripAuto(trim($filtered)));
        self::$produced[$filtered] = true;
        $final = self::withAuto($filtered, $hadAuto && $finalLazy, $sizes);
        self::$stats['corrected']++;
        if ($final !== $sizes) {
            do_action('srcset_truth_sizes_corrected', $sizes, $final, $context);
        }

        return $result($final, 'corrected');
    }

    /**
     * @param mixed $spec
     */
    private static function compileCached(string $template, string $slot, int $width, $spec): string|false
    {
        $key = $template . '|' . $slot . '|' . $width;
        if (array_key_exists($key, self::$compiled)) {
            return self::$compiled[$key];
        }
        self::$stats['compiles']++;
        try {
            if (!is_string($spec) && !is_array($spec)) {
                throw new InvalidSizesSpec('A sizes spec must be a string or a breakpoint list.');
            }
            self::$compiled[$key] = Compiler::compile($spec, $width > 0 ? $width : null);
        } catch (InvalidSizesSpec $e) {
            Logger::once('invalid-spec:' . $template . ':' . $slot, sprintf('Template "%s", slot "%s": %s Core value kept.', $template, $slot, $e->getMessage()));
            self::$compiled[$key] = false;
        }

        return self::$compiled[$key];
    }

    /**
     * Case 6: "auto" is valid only on a lazy tag.
     */
    public static function autoFix(string $sizes, bool $finalLazy): string
    {
        if ($finalLazy || !CoreDefault::hasAuto($sizes)) {
            return $sizes;
        }

        return trim(CoreDefault::stripAuto($sizes));
    }

    private static function withAuto(string $bare, bool $auto, string $original): string
    {
        $value = $auto ? 'auto, ' . $bare : $bare;
        // Keep the original bytes when nothing really changed.
        return trim((string) preg_replace('/\s+/', ' ', $original)) === $value ? $original : $value;
    }

    public static function isProduced(string $sizes): bool
    {
        return isset(self::$produced[trim(CoreDefault::stripAuto($sizes))]);
    }

    /**
     * @return array<string, int>
     */
    public static function stats(): array
    {
        return self::$stats;
    }

    public static function reset(): void
    {
        self::$compiled = [];
        self::$produced = [];
        self::$stats = ['corrected' => 0, 'unmapped' => 0, 'authored' => 0, 'invalid-spec' => 0, 'compiles' => 0];
    }
}

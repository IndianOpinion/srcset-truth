<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Layout;

/**
 * Built-in presets (PRD 7). "none" maps nothing. "grid" suits card-grid
 * themes: one column on phones, two on tablets, three on desktop.
 */
final class Presets
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function widthMap(string $preset): array
    {
        if ($preset !== 'grid') {
            return [];
        }
        $card = self::cardWidth();
        $content = self::contentWidth();
        $hero = '(max-width: 767px) 100vw, (max-width: 1200px) 90vw, 1200px';
        $contentSpec = sprintf('(max-width: %1$dpx) 100vw, %1$dpx', $content);

        return [
            'default' => [
                'hero' => $hero,
                'card' => sprintf('(max-width: 767px) 92vw, (max-width: 1024px) 46vw, %dpx', $card),
            ],
            // Singular views: content images fill the content column. Cards
            // there are declared at the content width too, so a featured
            // image painted wide is never under-declared.
            'single' => [
                'hero' => $hero,
                'content' => $contentSpec,
                'card' => $contentSpec,
            ],
            'page' => [
                'hero' => $hero,
                'content' => $contentSpec,
                'card' => $contentSpec,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function slotRules(string $preset): array
    {
        if ($preset !== 'grid') {
            return [];
        }

        return [
            ['slot' => 'content', 'source' => 'content'],
            ['slot' => 'hero', 'min_width' => 1200],
            ['slot' => 'card'],
        ];
    }

    public static function cardWidth(): int
    {
        $width = apply_filters('srcset_truth_grid_card_width', 380);

        return is_int($width) && $width > 0 ? $width : 380;
    }

    /**
     * The theme's $content_width when set, otherwise 1200.
     */
    public static function contentWidth(): int
    {
        $width = $GLOBALS['content_width'] ?? null;
        if (is_numeric($width) && (int) $width > 0) {
            return (int) $width;
        }

        return 1200;
    }
}

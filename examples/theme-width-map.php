<?php

/**
 * Width map and slot rules for a card-grid theme. Put this in the theme's
 * functions.php or a small site plugin. Measure first with the admin bar
 * "Probe image slots" link; these numbers are an example.
 */

declare(strict_types=1);

add_filter('srcset_truth_width_map', static function (array $map): array {
    // Any template: cards in a three-column grid, heroes at full width.
    $map['default']['card'] = [[767, '92vw'], [1024, '46vw'], [null, '380px']];
    $map['default']['hero'] = '(max-width: 767px) 100vw, (max-width: 1200px) 90vw, 1200px';
    $map['default']['thumb'] = '96px';

    // Single posts: content column of 760 px.
    $map['single']['content'] = [[800, '100vw'], [null, '760px']];

    // Category archives use a two-column grid.
    $map['taxonomy-category']['card'] = [[767, '92vw'], [null, '560px']];

    return $map;
});

add_filter('srcset_truth_slot_rules', static function (): array {
    return [
        ['slot' => 'thumb', 'class' => 'is-thumb'],
        ['slot' => 'content', 'source' => 'content'],
        ['slot' => 'hero', 'min_width' => 1200],
        ['slot' => 'card', 'size' => ['medium', 'medium_large', 'large']],
    ];
});

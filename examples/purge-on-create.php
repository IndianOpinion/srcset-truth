<?php

/**
 * The plugin never purges caches. If you want pages to pick up a new
 * sibling before their cache entry expires, purge here. This example
 * records the sibling and leaves the purge call to your cache plugin.
 */

declare(strict_types=1);

add_action('srcset_truth_sibling_created', static function (string $original, string $sibling, int $originalBytes, int $siblingBytes): void {
    if (function_exists('my_cache_purge_all')) {
        call_user_func('my_cache_purge_all');
    }
    error_log(sprintf('[site] WebP sibling %s saved %d bytes', basename($sibling), $originalBytes - $siblingBytes));
}, 10, 4);

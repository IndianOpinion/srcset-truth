<?php

/**
 * Uninstall: removes options, the lock, the cron event and any post meta
 * from older builds. Sibling files are kept on purpose: cached pages and
 * CDNs may still reference them. Run `wp srcset-truth remove-siblings --yes`
 * first, after a cache purge, to delete them.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    return;
}

foreach (['srcset_truth_settings', 'srcset_truth_backfill_state', 'srcset_truth_backfill_lock', 'srcset_truth_skips'] as $srcset_truth_option) {
    delete_option($srcset_truth_option);
}
wp_clear_scheduled_hook('srcset_truth_backfill');
delete_post_meta_by_key('_srcset_truth');

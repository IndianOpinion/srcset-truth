<?php

/**
 * Plugin Name:       srcset-truth
 * Plugin URI:        https://github.com/IndianOpinion/srcset-truth
 * Description:       Corrects the sizes attribute for images painted into fixed slots, serves WebP siblings, and audits page image weight.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       srcset-truth
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    return;
}

// One switch for incidents: define('SRCSET_TRUTH_DISABLE', true) in wp-config.php.
if (defined('SRCSET_TRUTH_DISABLE') && SRCSET_TRUTH_DISABLE === true) {
    return;
}

require_once __DIR__ . '/src/autoload.php';

if (function_exists('register_activation_hook')) {
    register_activation_hook(__FILE__, [\IndianOpinion\SrcsetTruth\Plugin::class, 'activate']);
    register_deactivation_hook(__FILE__, [\IndianOpinion\SrcsetTruth\Plugin::class, 'deactivate']);
}

\IndianOpinion\SrcsetTruth\Plugin::boot();

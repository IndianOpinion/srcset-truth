<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs/state.php';
require_once __DIR__ . '/stubs/wordpress.php';
require_once __DIR__ . '/stubs/wp-cli.php';

// The real WordPress HTML API (6.4, the minimum supported), from the
// johnpbloch/wordpress-core dev dependency.
$srcsetTruthHtmlApi = __DIR__ . '/../vendor/johnpbloch/wordpress-core/wp-includes/html-api/';
$srcsetTruthFiles = [
    'class-wp-html-attribute-token.php',
    'class-wp-html-span.php',
    'class-wp-html-text-replacement.php',
    'class-wp-html-tag-processor.php',
];
foreach ($srcsetTruthFiles as $srcsetTruthFile) {
    require_once $srcsetTruthHtmlApi . $srcsetTruthFile;
}

if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}

require_once __DIR__ . '/../src/autoload.php';

<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth;

/**
 * Request classification (PRD terms: front-end HTML, admin, REST, AJAX, feed,
 * sitemap, embed, WP-CLI, cron).
 */
final class Request
{
    public static function isFrontHtml(): bool
    {
        $front = !is_admin()
            && !wp_doing_ajax()
            && !wp_doing_cron()
            && !(defined('REST_REQUEST') && constant('REST_REQUEST'))
            && !(defined('WP_CLI') && constant('WP_CLI'))
            && !(defined('XMLRPC_REQUEST') && constant('XMLRPC_REQUEST'))
            && !is_feed()
            && !is_embed()
            && !self::isSitemap()
            && !self::isAmp()
            && !(function_exists('is_robots') && is_robots());

        return (bool) apply_filters('srcset_truth_is_front_html', $front);
    }

    public static function isSitemap(): bool
    {
        return function_exists('get_query_var') && get_query_var('sitemap') !== '' && get_query_var('sitemap') !== null;
    }

    public static function isAmp(): bool
    {
        foreach (['amp_is_request', 'is_amp_endpoint'] as $function) {
            if (function_exists($function)) {
                return (bool) $function();
            }
        }

        return false;
    }

    public static function method(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        return is_string($method) ? strtoupper($method) : 'GET';
    }
}

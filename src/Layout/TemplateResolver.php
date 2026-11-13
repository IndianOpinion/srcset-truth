<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Layout;

/**
 * Template keys (PRD cases 11 to 14).
 */
final class TemplateResolver
{
    private static ?string $cached = null;

    public static function current(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        if (!function_exists('did_action') || did_action('wp') === 0) {
            // Before the main query: do not cache, the real key comes later.
            return self::filter('default');
        }
        self::$cached = self::filter(self::compute());

        return self::$cached;
    }

    public static function compute(): string
    {
        if (is_front_page()) {
            return 'front';
        }
        if (is_home()) {
            return 'home';
        }
        if (is_attachment()) {
            return 'attachment';
        }
        if (is_page()) {
            return 'page';
        }
        if (is_singular()) {
            $type = get_post_type();

            return 'single-' . (is_string($type) && $type !== '' ? $type : 'post');
        }
        if (is_category()) {
            return 'taxonomy-category';
        }
        if (is_tag()) {
            return 'taxonomy-post_tag';
        }
        if (is_tax()) {
            $term = get_queried_object();
            $taxonomy = is_object($term) && isset($term->taxonomy) && is_string($term->taxonomy) ? $term->taxonomy : '';

            return $taxonomy !== '' ? 'taxonomy-' . $taxonomy : 'taxonomy';
        }
        if (is_author()) {
            return 'author';
        }
        if (is_date()) {
            return 'date';
        }
        if (is_post_type_archive()) {
            $type = get_query_var('post_type');
            if (is_array($type)) {
                $type = reset($type);
            }

            return is_string($type) && $type !== '' ? 'archive-' . $type : 'archive';
        }
        if (is_search()) {
            return 'search';
        }
        if (is_404()) {
            return '404';
        }

        return 'default';
    }

    private static function filter(string $computed): string
    {
        $filtered = apply_filters('srcset_truth_template', $computed);

        return is_string($filtered) && trim($filtered) !== '' ? trim($filtered) : $computed;
    }

    /**
     * Lookup order from specific to generic (case 12).
     *
     * @return list<string>
     */
    public static function chain(string $key): array
    {
        if ($key === 'default' || $key === '*') {
            return ['default'];
        }
        if (str_starts_with($key, 'single-')) {
            return [$key, 'single', 'default'];
        }
        if (str_starts_with($key, 'taxonomy-')) {
            return [$key, 'taxonomy', 'archive', 'default'];
        }
        if ($key === 'taxonomy') {
            return ['taxonomy', 'archive', 'default'];
        }
        if (str_starts_with($key, 'archive-')) {
            return [$key, 'archive', 'default'];
        }

        return [$key, 'default'];
    }

    public static function reset(): void
    {
        self::$cached = null;
    }
}

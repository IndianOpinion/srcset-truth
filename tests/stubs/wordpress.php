<?php

/**
 * A small WordPress stub layer: enough of the hook, option, query, upload,
 * HTTP and escaping APIs for the plugin to run under PHPUnit without
 * WordPress. Behaviour follows core where the plugin depends on it.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WPStub::$filters[$hook][$priority][] = [$callback, $args];
    ksort(WPStub::$filters[$hook]);

    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function has_filter(string $hook): bool
{
    return !empty(WPStub::$filters[$hook]);
}

function remove_all_filters(string $hook): void
{
    unset(WPStub::$filters[$hook]);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach (WPStub::$filters[$hook] ?? [] as $list) {
        foreach ($list as [$callback, $count]) {
            $value = $callback(...array_slice(array_merge([$value], $args), 0, max(1, $count)));
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WPStub::$actionsDone[$hook] = (WPStub::$actionsDone[$hook] ?? 0) + 1;
    foreach (WPStub::$filters[$hook] ?? [] as $list) {
        foreach ($list as [$callback, $count]) {
            $callback(...array_slice($args, 0, $count));
        }
    }
}

function did_action(string $hook): int
{
    return WPStub::$actionsDone[$hook] ?? 0;
}

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WPStub::$options) ? WPStub::$options[$name] : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WPStub::$options[$name] = $value;
    if ($autoload !== null) {
        WPStub::$autoload[$name] = (bool) $autoload;
    }

    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = 'yes'): bool
{
    if (array_key_exists($name, WPStub::$options)) {
        return false;
    }
    WPStub::$options[$name] = $value;
    WPStub::$autoload[$name] = $autoload === true || $autoload === 'yes';

    return true;
}

function delete_option(string $name): bool
{
    $had = array_key_exists($name, WPStub::$options);
    unset(WPStub::$options[$name]);

    return $had;
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    return WPStub::$postMeta[$id][$key] ?? ($single ? '' : []);
}

function delete_post_meta_by_key(string $key): bool
{
    $found = false;
    foreach (WPStub::$postMeta as $id => $meta) {
        if (array_key_exists($key, $meta)) {
            unset(WPStub::$postMeta[$id][$key]);
            $found = true;
        }
    }

    return $found;
}

function wp_get_attachment_metadata(int $id, bool $unfiltered = false): mixed
{
    return WPStub::$attachmentMeta[$id] ?? false;
}

function get_attached_file(int $id, bool $unfiltered = false): string|false
{
    if (isset(WPStub::$attachedFiles[$id])) {
        return WPStub::$attachedFiles[$id];
    }
    $meta = WPStub::$attachmentMeta[$id] ?? null;

    return is_array($meta) && isset($meta['file']) ? WPStub::$basedir . '/' . $meta['file'] : false;
}

/**
 * @param string|array<int> $size
 * @return array{0: string, 1: int, 2: int, 3: bool}|false
 */
function wp_get_attachment_image_src(int $id, string|array $size = 'thumbnail'): array|false
{
    $meta = WPStub::$attachmentMeta[$id] ?? null;
    if (!is_array($meta)) {
        return false;
    }
    $dir = dirname((string) $meta['file']);
    if (is_string($size) && isset($meta['sizes'][$size])) {
        $s = $meta['sizes'][$size];

        return [WPStub::$baseurl . '/' . $dir . '/' . $s['file'], (int) $s['width'], (int) $s['height'], true];
    }

    return [WPStub::$baseurl . '/' . $meta['file'], (int) $meta['width'], (int) $meta['height'], false];
}

/**
 * @return array<string, mixed>
 */
function wp_upload_dir(?string $time = null, bool $create = true): array
{
    return ['basedir' => WPStub::$basedir, 'baseurl' => WPStub::$baseurl, 'error' => false];
}

function home_url(string $path = ''): string
{
    return rtrim(WPStub::$home, '/') . '/' . ltrim($path, '/');
}

function is_admin(): bool
{
    return WPStub::$isAdmin;
}

function wp_doing_ajax(): bool
{
    return WPStub::$doingAjax;
}

function wp_doing_cron(): bool
{
    return WPStub::$doingCron;
}

function srcset_truth_stub_conditional(string $name): bool
{
    return WPStub::$conditionals[$name] ?? false;
}

function is_feed(): bool { return srcset_truth_stub_conditional('feed'); }
function is_embed(): bool { return srcset_truth_stub_conditional('embed'); }
function is_robots(): bool { return srcset_truth_stub_conditional('robots'); }
function is_front_page(): bool { return srcset_truth_stub_conditional('front_page'); }
function is_home(): bool { return srcset_truth_stub_conditional('home'); }
function is_attachment(): bool { return srcset_truth_stub_conditional('attachment'); }
function is_page(): bool { return srcset_truth_stub_conditional('page'); }
function is_singular(): bool { return srcset_truth_stub_conditional('singular'); }
function is_category(): bool { return srcset_truth_stub_conditional('category'); }
function is_tag(): bool { return srcset_truth_stub_conditional('tag'); }
function is_tax(): bool { return srcset_truth_stub_conditional('tax'); }
function is_author(): bool { return srcset_truth_stub_conditional('author'); }
function is_date(): bool { return srcset_truth_stub_conditional('date'); }
function is_post_type_archive(): bool { return srcset_truth_stub_conditional('post_type_archive'); }
function is_search(): bool { return srcset_truth_stub_conditional('search'); }
function is_404(): bool { return srcset_truth_stub_conditional('404'); }

function get_post_type(): string|false
{
    return WPStub::$postType;
}

function get_queried_object(): ?object
{
    return WPStub::$queriedObject;
}

function get_query_var(string $name, mixed $default = ''): mixed
{
    return WPStub::$queryVars[$name] ?? $default;
}

function current_user_can(string $cap): bool
{
    return WPStub::$caps[$cap] ?? false;
}

function wp_create_nonce(string $action): string
{
    return substr(md5('nonce|' . $action), 0, 10);
}

function wp_verify_nonce(string $nonce, string $action): int|false
{
    return hash_equals(wp_create_nonce($action), $nonce) ? 1 : false;
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'test-salt-' . $scheme;
}

function wp_unslash(mixed $value): mixed
{
    return is_string($value) ? stripslashes($value) : $value;
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags($value));
}

/**
 * @param array<string, string> $args
 */
function add_query_arg(array $args, string $url = ''): string
{
    $url = $url !== '' ? $url : home_url('/');

    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
}

function esc_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('#^(javascript|vbscript|data):#i', $url) === 1) {
        return '';
    }

    return str_replace(['&', "'", '"', '<', '>'], ['&#038;', '&#039;', '&quot;', '%3C', '%3E'], str_replace('&amp;', '&', $url));
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function checked(mixed $a, mixed $b = true, bool $echo = true): string
{
    $out = (string) $a === (string) $b ? ' checked=\'checked\'' : '';
    if ($echo) {
        echo $out;
    }

    return $out;
}

function selected(mixed $a, mixed $b = true, bool $echo = true): string
{
    $out = (string) $a === (string) $b ? ' selected=\'selected\'' : '';
    if ($echo) {
        echo $out;
    }

    return $out;
}

function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
{
    WPStub::$settingsErrors[] = $code;
}

/**
 * @param array<string, mixed> $args
 */
function register_setting(string $group, string $name, array $args = []): void
{
    WPStub::$options['__registered'][$name] = $args;
}

function add_settings_section(string $id, string $title, callable $cb, string $page): void
{
}

/**
 * @param array<string, mixed> $args
 */
function add_settings_field(string $id, string $title, callable $cb, string $page, string $section = 'default', array $args = []): void
{
}

function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($data, $flags, $depth);
}

function wp_next_scheduled(string $hook): int|false
{
    return isset(WPStub::$cron[$hook]) ? WPStub::$cron[$hook]['time'] : false;
}

function wp_schedule_event(int $time, string $schedule, string $hook): bool
{
    WPStub::$cron[$hook] = ['time' => $time, 'schedule' => $schedule];

    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $had = isset(WPStub::$cron[$hook]) ? 1 : 0;
    unset(WPStub::$cron[$hook]);

    return $had;
}

function register_activation_hook(string $file, callable $cb): void
{
}

function register_deactivation_hook(string $file, callable $cb): void
{
}

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function srcset_truth_stub_http(string $method, string $url, array $args): array|WP_Error
{
    WPStub::$httpLog[] = ['method' => $method, 'url' => $url, 'args' => $args];

    return WPStub::$httpResponses[$url] ?? new WP_Error('http_request_failed', 'No stub response for ' . $url);
}

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_get(string $url, array $args = []): array|WP_Error
{
    return srcset_truth_stub_http('GET', $url, $args);
}

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_head(string $url, array $args = []): array|WP_Error
{
    return srcset_truth_stub_http('HEAD', $url, $args);
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code(mixed $response): int|string
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
}

function wp_remote_retrieve_header(mixed $response, string $name): string
{
    return is_array($response) ? (string) ($response['headers'][strtolower($name)] ?? '') : '';
}

function wp_remote_retrieve_body(mixed $response): string
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}

function _doing_it_wrong(string $function, string $message, string $version): void
{
    trigger_error($function . ': ' . $message, E_USER_NOTICE);
}

/**
 * @return list<string>
 */
function wp_kses_uri_attributes(): array
{
    return ['action', 'archive', 'background', 'cite', 'classid', 'codebase', 'data', 'formaction', 'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile', 'src', 'usemap', 'xmlns'];
}

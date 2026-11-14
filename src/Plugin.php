<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth;

use IndianOpinion\SrcsetTruth\Admin\Probe;
use IndianOpinion\SrcsetTruth\Admin\SettingsPage;
use IndianOpinion\SrcsetTruth\Admin\SiteHealth;
use IndianOpinion\SrcsetTruth\Backfill\Backfill;
use IndianOpinion\SrcsetTruth\Correction\Corrector;
use IndianOpinion\SrcsetTruth\Files\PathMapper;
use IndianOpinion\SrcsetTruth\Files\Siblings;
use IndianOpinion\SrcsetTruth\Files\SkipLedger;
use IndianOpinion\SrcsetTruth\Files\Uploads;
use IndianOpinion\SrcsetTruth\Layout\SlotResolver;
use IndianOpinion\SrcsetTruth\Layout\SlotStack;
use IndianOpinion\SrcsetTruth\Layout\TemplateResolver;
use IndianOpinion\SrcsetTruth\Layout\WidthMap;
use IndianOpinion\SrcsetTruth\Markup\BufferProcessor;
use IndianOpinion\SrcsetTruth\Markup\FilterMode;
use IndianOpinion\SrcsetTruth\Markup\Wrapper;

/**
 * Wiring: every hook the plugin adds is registered here and nowhere else.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    public const AUDIT_HEADER = 'X-Srcset-Truth-Audit';

    private static bool $booted = false;

    private static ?bool $audit = null;

    private static bool $streamed = false;

    public static function disabled(): bool
    {
        return defined('SRCSET_TRUTH_DISABLE') && constant('SRCSET_TRUTH_DISABLE') === true;
    }

    public static function boot(): void
    {
        if (self::$booted || self::disabled()) {
            return;
        }
        self::$booted = true;

        add_filter('wp_get_attachment_image_attributes', [FilterMode::class, 'attachmentAttributes'], 20, 3);
        add_filter('wp_get_attachment_image', [FilterMode::class, 'attachmentImage'], 20, 3);
        add_filter('wp_content_img_tag', [FilterMode::class, 'contentImage'], 20, 3);
        add_filter('post_thumbnail_html', [FilterMode::class, 'thumbnail'], 20, 4);
        add_action('template_redirect', [self::class, 'startBuffer'], 1);
        add_action('send_headers', [self::class, 'sendHeaders']);
        add_action('wp_head', [self::class, 'printPictureCss'], 5);
        add_action('shutdown', [SlotStack::class, 'closeAll']);

        add_filter('cron_schedules', [Backfill::class, 'schedules']);
        add_action(Backfill::HOOK, [Backfill::class, 'cron']);
        add_action('init', [Backfill::class, 'ensureScheduled']);
        Lifecycle::register();

        add_action('admin_init', [SettingsPage::class, 'register']);
        add_filter('site_status_tests', [SiteHealth::class, 'tests']);
        add_action('admin_bar_menu', [Probe::class, 'adminBar'], 100);
        add_action('template_redirect', [Probe::class, 'maybeStart'], 0);

        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('srcset-truth', Cli\Command::class);
        }
    }

    public static function activate(): void
    {
        Backfill::ensureScheduled();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Backfill::HOOK);
    }

    /**
     * WebP is served through <picture> by default (PRD goal 3, lesson 6), so
     * a browser without WebP falls back to the img. Returning false from the
     * filter rewrites the img's own candidates in place instead, which only
     * suits sites that accept dropping browsers without WebP.
     */
    public static function pictureWrap(): bool
    {
        return (bool) apply_filters('srcset_truth_picture_wrap', true);
    }

    public static function bufferEnabled(): bool
    {
        return (bool) apply_filters('srcset_truth_buffer_enabled', Settings::get('mode') === 'buffer');
    }

    /**
     * The LCP fix needs buffer mode: it must see every image on the page.
     */
    public static function lcpEnabled(): bool
    {
        return self::bufferEnabled() && (bool) apply_filters('srcset_truth_lcp_enabled', Settings::bool('lcp'));
    }

    public static function startBuffer(): void
    {
        if (!self::bufferEnabled() || !Request::isFrontHtml() || Request::method() !== 'GET') {
            return;
        }
        self::$streamed = false;
        ob_start([self::class, 'bufferCallback']);
    }

    /**
     * Output buffer callback. A buffer flushed in pieces (another plugin
     * called ob_flush, or streaming) is passed through untouched: the
     * plugin only rewrites a response it sees whole.
     */
    public static function bufferCallback(string $buffer, int $phase): string
    {
        if (self::$streamed) {
            return $buffer;
        }
        if (($phase & PHP_OUTPUT_HANDLER_FINAL) === 0 || ($phase & PHP_OUTPUT_HANDLER_START) === 0) {
            self::$streamed = true;
            Logger::once(
                'buffer-streamed',
                'Output was flushed before the end of the request (another buffer or a streaming response); buffer mode skipped this page.'
            );

            return $buffer;
        }
        $status = http_response_code();
        $type = null;
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0) {
                $type = trim(substr($header, 13));
            }
        }

        return self::processResponse($buffer, is_int($status) ? $status : 200, $type);
    }

    /**
     * Buffer mode only for 200 HTML responses (case 55b).
     */
    public static function processResponse(string $buffer, int $status, ?string $contentType): string
    {
        if ($status !== 200) {
            return $buffer;
        }
        if ($contentType !== null && preg_match('#^\s*(text/html|application/xhtml\+xml)\b#i', $contentType) !== 1) {
            return $buffer;
        }

        return BufferProcessor::process($buffer);
    }

    /**
     * An annotated (audit) response must never reach a shared cache: it is
     * sent no-store, and DONOTCACHEPAGE tells page-cache plugins that read
     * the output buffer rather than the headers to skip it.
     */
    public static function sendHeaders(): void
    {
        if (self::auditMode()) {
            Response::privateNoStore();
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
        }
    }

    public static function printPictureCss(): void
    {
        if (!self::pictureWrap() || !Request::isFrontHtml() || !(bool) apply_filters('srcset_truth_picture_css', true)) {
            return;
        }
        echo '<style id="srcset-truth-picture">picture.srcset-truth{display:contents}</style>' . "\n";
    }

    /**
     * Audit token: HMAC-SHA256 over a five-minute window, truncated to 128
     * bits, so only code holding this site's salts (WP-CLI on this site) can
     * ask a page to annotate its images. The key is derived from the nonce
     * salt under a label of its own, so a token can never stand in for a
     * core nonce or the other way round. A token is accepted for its own
     * window and the one before (at most ten minutes).
     */
    public static function auditToken(?int $time = null): string
    {
        $window = intdiv($time ?? time(), 300);
        $key = hash_hmac('sha256', 'srcset-truth-audit-key', wp_salt('nonce'));

        return substr(hash_hmac('sha256', 'srcset-truth-audit|' . $window, $key), 0, 32);
    }

    public static function auditMode(): bool
    {
        if (self::$audit !== null) {
            return self::$audit;
        }
        $given = $_SERVER['HTTP_X_SRCSET_TRUTH_AUDIT'] ?? '';
        if (!is_string($given) || strlen($given) !== 32) {
            return self::$audit = false;
        }
        $now = time();

        return self::$audit = hash_equals(self::auditToken($now), $given) || hash_equals(self::auditToken($now - 300), $given);
    }

    /**
     * Clear every per-request cache. Long-running processes and tests call it.
     */
    public static function resetRequest(): void
    {
        Settings::flush();
        Logger::reset();
        Corrector::reset();
        TemplateResolver::reset();
        WidthMap::reset();
        SlotResolver::reset();
        SlotStack::closeAll();
        PathMapper::reset();
        Siblings::reset();
        Uploads::reset();
        SkipLedger::reset();
        Wrapper::reset();
        Response::reset();
        self::$audit = null;
        self::$streamed = false;
    }

    public static function resetBoot(): void
    {
        self::$booted = false;
    }
}

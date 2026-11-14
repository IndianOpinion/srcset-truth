<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Admin;

use IndianOpinion\SrcsetTruth\Response;

/**
 * The slot probe for administrators (PRD 4.13). Logs real rendered widths to
 * the browser console so a developer can write the width map.
 */
final class Probe
{
    public const PARAM = 'srcset-truth-probe';

    public const NONCE = 'srcset-truth-probe';

    private static bool $active = false;

    /**
     * @param mixed $bar WP_Admin_Bar
     */
    public static function adminBar($bar): void
    {
        if (is_admin() || !current_user_can('manage_options') || !is_object($bar) || !method_exists($bar, 'add_node')) {
            return;
        }
        $url = add_query_arg([self::PARAM => '1', '_wpnonce' => wp_create_nonce(self::NONCE)]);
        $bar->add_node([
            'id' => 'srcset-truth-probe',
            'title' => esc_html__('Probe image slots', 'srcset-truth'),
            'href' => esc_url($url),
        ]);
    }

    public static function requested(): bool
    {
        if (!isset($_GET[self::PARAM]) || $_GET[self::PARAM] !== '1') {
            return false;
        }
        $nonce = isset($_GET['_wpnonce']) && is_string($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        return current_user_can('manage_options') && $nonce !== '' && wp_verify_nonce($nonce, self::NONCE) !== false;
    }

    public static function maybeStart(): void
    {
        if (is_admin() || !self::requested()) {
            return;
        }
        self::$active = true;
        Response::privateNoStore();
        add_action('wp_footer', [self::class, 'printScript'], 999);
    }

    public static function active(): bool
    {
        return self::$active;
    }

    public static function script(): string
    {
        return <<<'JS'
(function () {
  function report() {
    var rows = [];
    document.querySelectorAll('img').forEach(function (img, i) {
      var rect = img.getBoundingClientRect();
      var dpr = window.devicePixelRatio || 1;
      var need = Math.round(rect.width * dpr);
      var natural = img.naturalWidth || 0;
      rows.push({
        index: i,
        cssWidth: Math.round(rect.width),
        dpr: dpr,
        naturalWidth: natural,
        currentSrc: img.currentSrc || img.src,
        sizes: img.getAttribute('sizes') || '',
        oversized: need > 0 && natural > need * 1.5
      });
    });
    console.table(rows);
  }
  if (document.readyState === 'complete') { report(); } else { window.addEventListener('load', report); }
})();
JS;
    }

    public static function printScript(): void
    {
        if (!self::$active) {
            return;
        }
        echo '<script id="srcset-truth-probe">' . self::script() . "</script>\n";
    }

    public static function reset(): void
    {
        self::$active = false;
    }
}

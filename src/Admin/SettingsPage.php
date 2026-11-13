<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Admin;

use IndianOpinion\SrcsetTruth\Settings;

/**
 * One section on Settings, Media (PRD 5.7). The Settings API handles the
 * nonce and the manage_options check; sanitisation is Settings::sanitize.
 */
final class SettingsPage
{
    public static function register(): void
    {
        register_setting('media', Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [Settings::class, 'sanitize'],
            'default' => Settings::defaults(),
            'show_in_rest' => false,
        ]);
        add_settings_section('srcset-truth', __('Responsive images (srcset-truth)', 'srcset-truth'), [self::class, 'intro'], 'media');
        $fields = [
            'webp' => __('Serve WebP siblings', 'srcset-truth'),
            'quality' => __('WebP photo quality', 'srcset-truth'),
            'mode' => __('Mode', 'srcset-truth'),
            'lcp' => __('LCP fix (buffer mode only)', 'srcset-truth'),
            'preset' => __('Width map preset', 'srcset-truth'),
            'override' => __('Override authored sizes', 'srcset-truth'),
            'backfill' => __('Background backfill', 'srcset-truth'),
            'debug' => __('Debug log', 'srcset-truth'),
        ];
        foreach ($fields as $key => $label) {
            add_settings_field(
                'srcset-truth-' . $key,
                $label,
                [self::class, 'field'],
                'media',
                'srcset-truth',
                ['key' => $key, 'label_for' => 'srcset-truth-' . $key]
            );
        }
    }

    public static function intro(): void
    {
        echo '<p>' . esc_html__(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one translatable string.
            'Corrects the sizes attribute for images in fixed slots and serves WebP copies. Slot widths come from your theme through filters; see the plugin README.',
            'srcset-truth'
        ) . '</p>';
    }

    /**
     * @param array{key?: string} $args
     */
    public static function field(array $args): void
    {
        $key = $args['key'] ?? '';
        $value = Settings::get($key);
        $name = Settings::OPTION . '[' . $key . ']';
        $id = 'srcset-truth-' . $key;
        switch ($key) {
            case 'quality':
                printf(
                    '<input type="number" min="1" max="100" step="1" id="%s" name="%s" value="%d" class="small-text">',
                    esc_attr($id),
                    esc_attr($name),
                    (int) $value
                );
                break;
            case 'mode':
                self::select($id, $name, (string) $value, [
                    'filter' => __('Filter (default)', 'srcset-truth'),
                    'buffer' => __('Buffer (whole page)', 'srcset-truth'),
                ]);
                break;
            case 'preset':
                self::select($id, $name, (string) $value, ['none' => __('None', 'srcset-truth'), 'grid' => __('Card grid', 'srcset-truth')]);
                break;
            default:
                printf('<input type="checkbox" id="%s" name="%s" value="1" %s>', esc_attr($id), esc_attr($name), checked((bool) $value, true, false));
        }
    }

    /**
     * @param array<string, string> $options
     */
    private static function select(string $id, string $name, string $current, array $options): void
    {
        printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($name));
        foreach ($options as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
        }
        echo '</select>';
    }
}

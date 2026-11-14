<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Markup;

use IndianOpinion\SrcsetTruth\Correction\Corrector;
use IndianOpinion\SrcsetTruth\Layout\TemplateResolver;
use IndianOpinion\SrcsetTruth\Plugin;

/**
 * Applies every per-image rule to the IMG tag a processor is paused on.
 * Filter mode and buffer mode both come through here, so they cannot
 * disagree (PRD case 10).
 */
final class ImageProcessor
{
    public const WRAP_MARKER = 'data-srcset-truth-wrap';

    /**
     * @param array<string, mixed> $base Known context: attachment_id, size, source, image_width.
     * @param array{loading?: ?string, inside_picture?: bool, fill_dimensions?: bool, wrap_id?: int, no_wrap?: bool} $options
     *        loading: null leaves loading attributes alone; "lcp" or "lazy" applies the LCP fix.
     * @return array{wrap: ?array{srcset: string, sizes: ?string, identity: string}, attrs: array<string, string|true>, status: string}
     */
    public static function process(\WP_HTML_Tag_Processor $p, array $base, array $options = []): array
    {
        $attrs = Attributes::read($p);
        $loading = $options['loading'] ?? null;

        // LCP fix (case 58, 59): strip every copy, then write one of each.
        if ($loading !== null) {
            foreach (['loading', 'fetchpriority', 'decoding'] as $name) {
                $p->remove_attribute($name);
                unset($attrs[$name]);
            }
            if ($loading === 'lcp') {
                $p->set_attribute('fetchpriority', 'high');
                $p->set_attribute('decoding', 'async');
                $attrs['fetchpriority'] = 'high';
                $attrs['decoding'] = 'async';
            } else {
                $p->set_attribute('loading', 'lazy');
                $p->set_attribute('decoding', 'async');
                $attrs['loading'] = 'lazy';
                $attrs['decoding'] = 'async';
            }
        }
        $finalLazy = is_string($attrs['loading'] ?? null) && strtolower(trim($attrs['loading'])) === 'lazy';

        // Case 63: reserve space from the real file when dimensions are missing.
        if (!empty($options['fill_dimensions']) && (!isset($attrs['width']) || !isset($attrs['height']))) {
            if (Lcp::fileDimensions($attrs) !== null) {
                [$w, $h] = Lcp::dimensions($attrs);
                if ($w !== null && $h !== null) {
                    $p->set_attribute('width', (string) $w);
                    $p->set_attribute('height', (string) $h);
                    $attrs['width'] = (string) $w;
                    $attrs['height'] = (string) $h;
                }
            }
        }

        $context = self::context($attrs, $base);
        $sizes = is_string($attrs['sizes'] ?? null) ? $attrs['sizes'] : null;
        $srcset = is_string($attrs['srcset'] ?? null) ? trim($attrs['srcset']) : '';
        $status = 'none';
        if ($sizes !== null) {
            $result = Corrector::correct($sizes, $context, $finalLazy, $srcset !== '');
            $status = $result['status'];
            $context = $result['context'];
            if ($result['changed']) {
                $p->set_attribute('sizes', $result['sizes']);
                $attrs['sizes'] = $result['sizes'];
            }
        }

        $wrap = null;
        $identity = Wrapper::identity(
            is_string($attrs['src'] ?? null) ? $attrs['src'] : '',
            is_string($attrs['srcset'] ?? null) ? $attrs['srcset'] : ''
        );
        $webpAllowed = empty($options['inside_picture'])
            && !(!empty($options['no_wrap']) && Plugin::pictureWrap())
            && !(($context['source'] ?? '') === 'content' && Wrapper::wasWrapped($identity));
        if ($webpAllowed) {
            $plan = WebpPlanner::plan($attrs, $context);
            if ($plan !== null) {
                if (Plugin::pictureWrap()) {
                    // Case 50: the source carries the final sizes of the img.
                    $finalSizes = is_string($attrs['sizes'] ?? null) ? $attrs['sizes'] : null;
                    $wrap = ['srcset' => $plan['source_srcset'], 'sizes' => $finalSizes, 'identity' => $identity];
                    $p->set_attribute(self::WRAP_MARKER, (string) ($options['wrap_id'] ?? 0));
                } else {
                    if ($plan['srcset'] !== null) {
                        $p->set_attribute('srcset', $plan['srcset']);
                        $attrs['srcset'] = $plan['srcset'];
                    }
                    if ($plan['src'] !== null) {
                        $p->set_attribute('src', $plan['src']);
                        $attrs['src'] = $plan['src'];
                    }
                }
            }
        }

        if (Plugin::auditMode()) {
            $note = sprintf('%s|%s|%s', (string) $context['template'], (string) ($context['slot'] ?? ''), $status);
            $p->set_attribute('data-srcset-truth', $note);
            $attrs['data-srcset-truth'] = $note;
        }

        return ['wrap' => $wrap, 'attrs' => $attrs, 'status' => $status];
    }

    /**
     * @param array<string, string|true> $attrs
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    public static function context(array $attrs, array $base): array
    {
        $class = is_string($attrs['class'] ?? null) ? $attrs['class'] : '';
        $attachmentId = (int) ($base['attachment_id'] ?? 0);
        if ($attachmentId === 0 && preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class, $m) === 1) {
            $attachmentId = (int) $m[1];
        }
        $size = $base['size'] ?? null;
        if (($size === null || $size === '') && preg_match('/(?:^|\s)size-([a-z0-9_-]+)(?:\s|$)/i', $class, $m) === 1) {
            $size = $m[1];
        }
        $width = (int) ($base['image_width'] ?? 0);
        if ($width === 0 && is_string($attrs['width'] ?? null) && preg_match('/^\s*(\d+)/', $attrs['width'], $m) === 1) {
            $width = (int) $m[1];
        }

        return [
            'template' => TemplateResolver::current(),
            'slot' => null,
            'attachment_id' => $attachmentId,
            'size' => $size,
            'image_width' => $width,
            'src' => is_string($attrs['src'] ?? null) ? $attrs['src'] : '',
            'class' => $class,
            'source' => (string) ($base['source'] ?? 'buffer'),
        ];
    }
}

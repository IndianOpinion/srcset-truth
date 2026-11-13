<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Markup;

/**
 * Wraps marked IMG tags in <picture> with a WebP <source> (PRD 46).
 *
 * The tag processor marks each tag with a numbered attribute. The processor
 * always writes a new attribute straight after the tag name, so the marker
 * sits at a known offset from the "<img" that opens the tag. The tag end is
 * found with a quote-aware scan. When anything does not line up, the marker
 * is removed and the tag is left unwrapped: fail open.
 */
final class Wrapper
{
    private static int $counter = 0;

    public static function nextId(): int
    {
        return ++self::$counter;
    }

    /**
     * @param array<int, array{srcset: string, sizes: ?string}> $wraps
     */
    public static function apply(string $html, array $wraps): string
    {
        foreach ($wraps as $id => $wrap) {
            $marker = sprintf(' %s="%d"', ImageProcessor::WRAP_MARKER, $id);
            $at = strpos($html, $marker);
            if ($at === false) {
                continue;
            }
            $start = $at - 4;
            if ($start < 0 || strcasecmp(substr($html, $start, 4), '<img') !== 0) {
                $html = substr_replace($html, '', $at, strlen($marker));
                continue;
            }
            $end = self::tagEnd($html, $at + strlen($marker));
            if ($end === null) {
                $html = substr_replace($html, '', $at, strlen($marker));
                continue;
            }
            $tag = substr($html, $start, $end - $start + 1);
            $tag = substr_replace($tag, '', 4, strlen($marker));
            $source = sprintf(
                '<source type="image/webp" srcset="%s"%s>',
                esc_attr($wrap['srcset']),
                $wrap['sizes'] !== null && $wrap['sizes'] !== '' ? sprintf(' sizes="%s"', esc_attr($wrap['sizes'])) : ''
            );
            $picture = '<picture class="srcset-truth">' . $source . $tag . '</picture>';
            $html = substr_replace($html, $picture, $start, $end - $start + 1);
        }

        return $html;
    }

    /**
     * Offset of the ">" that closes the tag, honouring quoted values.
     */
    private static function tagEnd(string $html, int $from): ?int
    {
        $length = strlen($html);
        $quote = null;
        for ($i = $from; $i < $length; $i++) {
            $char = $html[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                // Only a quote straight after "=" (with optional spaces) opens a value.
                $back = rtrim(substr($html, max($from, $i - 8), $i - max($from, $i - 8)));
                if (str_ends_with($back, '=')) {
                    $quote = $char;
                }
                continue;
            }
            if ($char === '>') {
                return $i;
            }
        }

        return null;
    }
}

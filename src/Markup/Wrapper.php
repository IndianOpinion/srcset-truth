<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Markup;

/**
 * Wraps marked IMG tags in <picture> with a WebP <source> (PRD 46).
 *
 * The tag processor marks each tag with a numbered attribute. The processor
 * always writes a new attribute straight after the tag name, so the "<img"
 * that opens the tag is the last "<" before the marker. The tag end is
 * found with a quote-aware scan. When anything does not line up, the marker
 * is removed and the tag is left unwrapped: fail open.
 */
final class Wrapper
{
    private static int $counter = 0;

    /** @var array<string, true> Identities (src and srcset) of images wrapped in this request. */
    private static array $wrapped = [];

    public static function nextId(): int
    {
        return ++self::$counter;
    }

    /**
     * Identity of an image for wasWrapped(): its src and srcset as written.
     */
    public static function identity(string $src, string $srcset): string
    {
        return md5($src . "\n" . trim($srcset));
    }

    /**
     * True when an image with this identity was wrapped earlier in the
     * request. Core runs the content image filter again over HTML that
     * already holds our <picture> (block theme templates, shortcode output
     * in post content), and hands the filter the bare <img> without its
     * parent, so this is the only way to see that it is already wrapped.
     */
    public static function wasWrapped(string $identity): bool
    {
        return isset(self::$wrapped[$identity]);
    }

    public static function reset(): void
    {
        self::$wrapped = [];
    }

    /**
     * @param array<int, array{srcset: string, sizes: ?string, identity?: string}> $wraps
     */
    public static function apply(string $html, array $wraps): string
    {
        foreach ($wraps as $id => $wrap) {
            $marker = sprintf(' %s="%d"', ImageProcessor::WRAP_MARKER, $id);
            $at = strpos($html, $marker);
            if ($at === false) {
                continue;
            }
            $start = self::tagStart($html, $at);
            if ($start === null) {
                $html = substr_replace($html, '', $at, strlen($marker));
                continue;
            }
            $end = self::tagEnd($html, $at + strlen($marker));
            if ($end === null) {
                $html = substr_replace($html, '', $at, strlen($marker));
                continue;
            }
            $tag = substr($html, $start, $end - $start + 1);
            $tag = substr_replace($tag, '', $at - $start, strlen($marker));
            $source = sprintf(
                '<source type="image/webp" srcset="%s"%s>',
                esc_attr($wrap['srcset']),
                $wrap['sizes'] !== null && $wrap['sizes'] !== '' ? sprintf(' sizes="%s"', esc_attr($wrap['sizes'])) : ''
            );
            $picture = '<picture class="srcset-truth">' . $source . $tag . '</picture>';
            $html = substr_replace($html, $picture, $start, $end - $start + 1);
            if (isset($wrap['identity'])) {
                self::$wrapped[$wrap['identity']] = true;
            }
        }

        return $html;
    }

    /**
     * Offset of the "<img" that opens the tag holding the marker, or null.
     *
     * The processor inserts every new attribute straight after the tag
     * name, so only processor-written attributes (width, height, loading,
     * decoding, fetchpriority when the LCP fix adds them) can sit between
     * "<img" and the marker. Their values are escaped, so none holds a
     * raw "<" and the last "<" before the marker opens the tag.
     */
    private static function tagStart(string $html, int $at): ?int
    {
        $start = strrpos(substr($html, 0, $at), '<');
        if ($start === false || strcasecmp(substr($html, $start, 4), '<img') !== 0) {
            return null;
        }
        $after = substr($html, $start + 4, 1);

        return $after !== '' && strspn($after, " \t\n\r\f/") === 1 ? $start : null;
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

<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Markup;

use IndianOpinion\SrcsetTruth\Encoding\SiblingCreator;
use IndianOpinion\SrcsetTruth\Files\PathMapper;
use IndianOpinion\SrcsetTruth\Files\Siblings;

/**
 * Decides which candidates of one image can be served as WebP (PRD 47 to 54).
 *
 * The plan keeps every candidate in place with its descriptor. A candidate
 * with a fresh sibling gets the sibling URL; one without keeps the original
 * URL (lesson 5). Only JPEG, PNG and GIF originals are mapped: a candidate
 * that is already WebP, AVIF or SVG is left as it is. This is the
 * "type-aware" part. It only checks; it never encodes (case 38).
 */
final class WebpPlanner
{
    /**
     * @param array<string, string|true> $attrs
     * @param array<string, mixed> $context
     * @return array{srcset: ?string, src: ?string, source_srcset: string}|null
     */
    public static function plan(array $attrs, array $context): ?array
    {
        if (!SiblingCreator::enabled()) {
            return null;
        }
        $src = is_string($attrs['src'] ?? null) ? trim($attrs['src']) : '';
        $srcset = is_string($attrs['srcset'] ?? null) ? trim($attrs['srcset']) : '';
        if ($src === '' && $srcset === '') {
            return null;
        }
        if (str_starts_with(strtolower($src), 'data:')) {
            return null;
        }
        if ($src !== '' && preg_match('/\.(svg|webp|avif)$/i', substr($src, 0, strcspn($src, '?#'))) === 1) {
            return null;
        }
        // Script lazy loaders keep the real list in data-* until JavaScript runs.
        if ($srcset === '' && (isset($attrs['data-src']) || isset($attrs['data-srcset']))) {
            return null;
        }
        if (isset($attrs['data-srcset']) && $srcset === '') {
            return null;
        }
        $skip = apply_filters('srcset_truth_skip_classes', []);
        $skip = is_array($skip) ? array_filter($skip, 'is_string') : [];
        $classes = Attributes::classes(is_string($attrs['class'] ?? null) ? $attrs['class'] : null);
        if ($skip !== [] && array_intersect($skip, $classes) !== []) {
            return null;
        }
        if (!(bool) apply_filters('srcset_truth_should_wrap', true, Attributes::toTag('img', $attrs), $context)) {
            return null;
        }

        $newSrc = $src !== '' ? self::siblingFor($src) : null;
        if ($srcset === '') {
            if ($newSrc === null) {
                return null;
            }

            return ['srcset' => null, 'src' => $newSrc, 'source_srcset' => $newSrc];
        }
        $candidates = Srcset::parse($srcset);
        $any = false;
        foreach ($candidates as $i => $candidate) {
            $sibling = self::siblingFor($candidate['url']);
            if ($sibling !== null) {
                $candidates[$i]['url'] = $sibling;
                $any = true;
            }
        }
        if (!$any) {
            return null;
        }
        $list = Srcset::serialise($candidates);

        return ['srcset' => $list, 'src' => $newSrc, 'source_srcset' => $list];
    }

    /**
     * The sibling URL for an original URL when a fresh sibling exists.
     */
    public static function siblingFor(string $url): ?string
    {
        if (!Siblings::hasSourceExtension($url)) {
            return null;
        }
        $path = PathMapper::urlToPath($url);
        if ($path === null || !Siblings::isFresh($path)) {
            return null;
        }

        return Siblings::url($url);
    }
}

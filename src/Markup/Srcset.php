<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Markup;

/**
 * Parses and serialises srcset candidate lists, following the HTML
 * specification's parsing rules closely enough to keep URLs with commas.
 */
final class Srcset
{
    /**
     * @return list<array{url: string, descriptor: string}>
     */
    public static function parse(string $srcset): array
    {
        $candidates = [];
        $length = strlen($srcset);
        $pos = 0;
        while ($pos < $length) {
            // Skip whitespace and commas between candidates.
            $pos += strspn($srcset, " \t\n\r\f,", $pos);
            if ($pos >= $length) {
                break;
            }
            $urlLength = strcspn($srcset, " \t\n\r\f", $pos);
            $url = substr($srcset, $pos, $urlLength);
            $pos += $urlLength;
            $descriptor = '';
            if (str_ends_with($url, ',')) {
                $url = rtrim($url, ',');
            } else {
                $depth = 0;
                $start = $pos;
                while ($pos < $length) {
                    $char = $srcset[$pos];
                    if ($char === '(') {
                        $depth++;
                    } elseif ($char === ')') {
                        $depth = max(0, $depth - 1);
                    } elseif ($char === ',' && $depth === 0) {
                        break;
                    }
                    $pos++;
                }
                $descriptor = trim((string) preg_replace('/\s+/', ' ', substr($srcset, $start, $pos - $start)));
                $pos++;
            }
            if ($url !== '') {
                $candidates[] = ['url' => $url, 'descriptor' => $descriptor];
            }
        }

        return $candidates;
    }

    /**
     * @param list<array{url: string, descriptor: string}> $candidates
     */
    public static function serialise(array $candidates): string
    {
        $parts = [];
        foreach ($candidates as $candidate) {
            $parts[] = trim($candidate['url'] . ' ' . $candidate['descriptor']);
        }

        return implode(', ', $parts);
    }

    /**
     * Width of a "123w" descriptor, or null.
     */
    public static function width(string $descriptor): ?int
    {
        return preg_match('/^(\d+)w$/i', trim($descriptor), $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Density of an "1.5x" descriptor (empty means 1x), or null.
     */
    public static function density(string $descriptor): ?float
    {
        $descriptor = trim($descriptor);
        if ($descriptor === '') {
            return 1.0;
        }

        return preg_match('/^(\d+(?:\.\d+)?|\.\d+)x$/i', $descriptor, $m) === 1 ? (float) $m[1] : null;
    }
}

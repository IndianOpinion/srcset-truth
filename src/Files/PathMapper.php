<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Files;

/**
 * Maps a candidate URL to a file under the uploads directory (PRD 51c, 52).
 */
final class PathMapper
{
    /** @var array<string, string|null> */
    private static array $memo = [];

    public static function urlToPath(string $url): ?string
    {
        if (array_key_exists($url, self::$memo)) {
            return self::$memo[$url];
        }
        $default = self::defaultMapping($url);
        $filtered = apply_filters('srcset_truth_url_to_path', $default, $url);
        $path = null;
        if (is_string($filtered) && $filtered !== '') {
            $normal = Uploads::normalise($filtered);
            $path = $normal !== '' && Uploads::contains($normal) ? $normal : null;
        }
        self::$memo[$url] = $path;

        return $path;
    }

    public static function defaultMapping(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || preg_match('#^(data|blob|javascript):#i', $url) === 1) {
            return null;
        }
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        $query = '';
        $qmark = strpos($url, '?');
        if ($qmark !== false) {
            $query = substr($url, $qmark + 1);
            $url = substr($url, 0, $qmark);
        }
        if ($query !== '' && !self::onlyCacheBusting($query)) {
            // A query that may change the image (resize, crop) is not the file on disk.
            return null;
        }
        $uploads = Uploads::dir();
        if ($uploads['basedir'] === '' || $uploads['baseurl'] === '') {
            return null;
        }
        $base = self::split($uploads['baseurl']);
        $candidate = self::split($url);
        if ($base === null || $candidate === null) {
            return null;
        }
        if ($candidate['host'] !== '' && $candidate['host'] !== $base['host']) {
            return null;
        }
        $prefix = rtrim($base['path'], '/') . '/';
        if (!str_starts_with($candidate['path'], $prefix)) {
            return null;
        }
        $remainder = rawurldecode(substr($candidate['path'], strlen($prefix)));
        if ($remainder === '' || str_contains($remainder, "\0") || str_contains($remainder, '\\')) {
            return null;
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $remainder) === 1) {
            return null;
        }
        $path = $uploads['basedir'] . '/' . $remainder;

        return Uploads::contains($path) ? $path : null;
    }

    private static function onlyCacheBusting(string $query): bool
    {
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            if ($name !== 'ver') {
                return false;
            }
        }

        return true;
    }

    /**
     * Host (lower case, with port) and path. Scheme may be http, https or
     * absent (protocol-relative or root-relative). Other schemes are refused.
     *
     * @return array{host: string, path: string}|null
     */
    private static function split(string $url): ?array
    {
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        if (str_starts_with($url, '/')) {
            return ['host' => '', 'path' => $url];
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return ['host' => $host, 'path' => $parts['path'] ?? '/'];
    }

    public static function reset(): void
    {
        self::$memo = [];
    }
}

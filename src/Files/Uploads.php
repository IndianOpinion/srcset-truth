<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Files;

/**
 * The uploads base directory and URL, read once per request.
 */
final class Uploads
{
    /** @var array{basedir: string, baseurl: string}|null */
    private static ?array $dir = null;

    /**
     * @return array{basedir: string, baseurl: string}
     */
    public static function dir(): array
    {
        if (self::$dir === null) {
            $uploads = wp_upload_dir(null, false);
            $basedir = isset($uploads['basedir']) && is_string($uploads['basedir']) ? $uploads['basedir'] : '';
            $baseurl = isset($uploads['baseurl']) && is_string($uploads['baseurl']) ? $uploads['baseurl'] : '';
            self::$dir = [
                'basedir' => rtrim(self::normalise($basedir), '/'),
                'baseurl' => rtrim($baseurl, '/'),
            ];
        }

        return self::$dir;
    }

    /**
     * Lexical normalisation: forward slashes, no "." or ".." segments.
     * Returns '' when ".." would climb above the root.
     */
    public static function normalise(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return '';
        }
        $path = str_replace('\\', '/', $path);
        $absolute = str_starts_with($path, '/');
        $prefix = '';
        if (preg_match('#^([A-Za-z]:)/#', $path, $m) === 1) {
            $prefix = $m[1];
            $path = substr($path, 2);
            $absolute = true;
        }
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out === []) {
                    return '';
                }
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return $prefix . ($absolute ? '/' : '') . implode('/', $out);
    }

    /**
     * True when the path resolves inside the uploads base directory.
     */
    public static function contains(string $path): bool
    {
        $base = self::dir()['basedir'];
        $normal = self::normalise($path);
        if ($base === '' || $normal === '' || !str_starts_with($normal, $base . '/')) {
            return false;
        }
        // Follow symlinks when both ends exist, so a link cannot lead out.
        $realBase = realpath($base);
        $realDir = realpath(dirname($normal));
        if ($realBase !== false && $realDir !== false) {
            return $realDir === $realBase || str_starts_with($realDir . '/', $realBase . '/');
        }

        return true;
    }

    public static function relative(string $path): string
    {
        $base = self::dir()['basedir'];
        $normal = self::normalise($path);

        return str_starts_with($normal, $base . '/') ? substr($normal, strlen($base) + 1) : $normal;
    }

    public static function reset(): void
    {
        self::$dir = null;
    }
}

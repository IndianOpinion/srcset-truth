<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Files;

/**
 * The files of an attachment, derived from its metadata (PRD 25, 43, 44).
 */
final class AttachmentFiles
{
    public const DEFAULT_SIZES = ['medium', 'medium_large', 'large'];

    /**
     * The files that get siblings: the main file plus the configured sizes.
     *
     * @param array<mixed>|null $meta Metadata to use instead of the stored copy.
     * @return list<string>
     */
    public static function served(int $attachmentId, ?array $meta = null): array
    {
        $meta = $meta ?? self::meta($attachmentId);
        $main = self::mainFile($attachmentId, $meta);
        if ($main === null) {
            return [];
        }
        $files = [$main];
        $wanted = apply_filters('srcset_truth_webp_sizes', self::DEFAULT_SIZES, $attachmentId);
        $wanted = is_array($wanted) ? array_values(array_filter($wanted, 'is_string')) : self::DEFAULT_SIZES;
        $sizes = isset($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : [];
        foreach ($wanted as $name) {
            $file = $sizes[$name]['file'] ?? null;
            if (is_string($file) && $file !== '') {
                $files[] = dirname($main) . '/' . basename($file);
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Every file core knows for the attachment: main, all sizes, the
     * pre-scaling original and backup sizes from image edits.
     *
     * @param array<mixed>|null $meta
     * @return list<string>
     */
    public static function all(int $attachmentId, ?array $meta = null): array
    {
        $meta = $meta ?? self::meta($attachmentId);
        $main = self::mainFile($attachmentId, $meta);
        if ($main === null) {
            return [];
        }
        $dir = dirname($main);
        $files = [$main];
        foreach (isset($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : [] as $size) {
            if (is_array($size) && isset($size['file']) && is_string($size['file'])) {
                $files[] = $dir . '/' . basename($size['file']);
            }
        }
        if (isset($meta['original_image']) && is_string($meta['original_image'])) {
            $files[] = $dir . '/' . basename($meta['original_image']);
        }
        $backups = get_post_meta($attachmentId, '_wp_attachment_backup_sizes', true);
        foreach (is_array($backups) ? $backups : [] as $backup) {
            if (is_array($backup) && isset($backup['file']) && is_string($backup['file'])) {
                $files[] = $dir . '/' . basename($backup['file']);
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param array<mixed> $meta
     */
    public static function mainFile(int $attachmentId, array $meta): ?string
    {
        $uploads = Uploads::dir();
        if (isset($meta['file']) && is_string($meta['file']) && $meta['file'] !== '') {
            $file = $meta['file'];
            $path = str_starts_with($file, '/') ? $file : $uploads['basedir'] . '/' . $file;
        } else {
            $attached = get_attached_file($attachmentId, true);
            if (!is_string($attached) || $attached === '') {
                return null;
            }
            $path = $attached;
        }
        $path = Uploads::normalise($path);

        return $path !== '' && Uploads::contains($path) ? $path : null;
    }

    /**
     * @return array<mixed>
     */
    private static function meta(int $attachmentId): array
    {
        $meta = wp_get_attachment_metadata($attachmentId, true);

        return is_array($meta) ? $meta : [];
    }
}

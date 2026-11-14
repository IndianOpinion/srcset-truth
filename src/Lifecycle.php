<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth;

use IndianOpinion\SrcsetTruth\Encoding\SiblingCreator;
use IndianOpinion\SrcsetTruth\Files\AttachmentFiles;
use IndianOpinion\SrcsetTruth\Files\Siblings;
use IndianOpinion\SrcsetTruth\Files\SkipLedger;

/**
 * Siblings follow their attachment: created on upload and edit, removed on
 * delete (PRD 25, 43 to 45, lessons 3 and 16).
 */
final class Lifecycle
{
    public static function register(): void
    {
        add_filter('wp_generate_attachment_metadata', [self::class, 'onGenerate'], 20, 2);
        add_filter('wp_update_attachment_metadata', [self::class, 'onUpdate'], 20, 2);
        add_action('delete_attachment', [self::class, 'onDelete'], 10, 1);
        add_filter('wp_delete_file', [self::class, 'onDeleteFile'], 10, 1);
    }

    /**
     * @param mixed $meta
     * @param mixed $attachmentId
     * @return mixed
     */
    public static function onGenerate($meta, $attachmentId = 0)
    {
        if (is_array($meta) && (int) $attachmentId > 0) {
            self::createFor((int) $attachmentId, $meta);
        }

        return $meta;
    }

    /**
     * Edits in the media editor and thumbnail regeneration save new metadata
     * through here. Fresh siblings are skipped cheaply.
     *
     * @param mixed $meta
     * @param mixed $attachmentId
     * @return mixed
     */
    public static function onUpdate($meta, $attachmentId = 0)
    {
        if (is_array($meta) && isset($meta['file']) && (int) $attachmentId > 0) {
            self::createFor((int) $attachmentId, $meta);
        }

        return $meta;
    }

    /**
     * @param array<mixed> $meta
     * @return array<string, int> status => count
     */
    public static function createFor(int $attachmentId, array $meta): array
    {
        $counts = [];
        if (!SiblingCreator::enabled()) {
            return $counts;
        }
        foreach (AttachmentFiles::served($attachmentId, $meta) as $file) {
            try {
                $result = SiblingCreator::create($file);
                $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            } catch (\Throwable $e) {
                Logger::log(sprintf('Sibling creation threw for attachment %d: %s', $attachmentId, $e->getMessage()));
                $counts['failed'] = ($counts['failed'] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param mixed $attachmentId
     */
    public static function onDelete($attachmentId): void
    {
        $id = (int) $attachmentId;
        if ($id <= 0) {
            return;
        }
        foreach (AttachmentFiles::all($id) as $file) {
            SiblingCreator::removeSibling($file);
            SkipLedger::forget($file);
        }
    }

    /**
     * Core deletes a file it knows about: delete its sibling with it. This
     * also covers files the media editor replaces.
     *
     * @param mixed $file
     * @return mixed
     */
    public static function onDeleteFile($file)
    {
        if (is_string($file) && $file !== '' && Siblings::hasSourceExtension($file)) {
            SiblingCreator::removeSibling($file);
        }

        return $file;
    }
}

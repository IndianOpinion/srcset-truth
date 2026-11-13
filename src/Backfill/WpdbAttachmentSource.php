<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Backfill;

/**
 * Reads attachment IDs straight from the posts table with prepared queries.
 */
final class WpdbAttachmentSource implements AttachmentSource
{
    private const TYPES = "'image/jpeg','image/png','image/gif'";

    public function idsBelow(?int $below, int $limit): array
    {
        global $wpdb;
        $limit = max(1, $limit);
        if ($below === null) {
            $sql = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (" . self::TYPES . ") ORDER BY ID DESC LIMIT %d",
                $limit
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (" . self::TYPES . ") AND ID < %d ORDER BY ID DESC LIMIT %d",
                $below,
                $limit
            );
        }

        return array_values(array_map('intval', (array) $wpdb->get_col((string) $sql)));
    }

    public function idsSince(string $date, int $limit, int $offset): array
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (" . self::TYPES . ") AND post_date >= %s ORDER BY ID DESC LIMIT %d OFFSET %d",
            $date,
            max(1, $limit),
            max(0, $offset)
        );

        return array_values(array_map('intval', (array) $wpdb->get_col((string) $sql)));
    }
}

<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Backfill;

/**
 * Where the backfill and the CLI find image attachments.
 */
interface AttachmentSource
{
    /**
     * Image attachment IDs below $below (all when null), newest first.
     *
     * @return list<int>
     */
    public function idsBelow(?int $below, int $limit): array;

    /**
     * Image attachment IDs uploaded on or after a date, newest first.
     *
     * @return list<int>
     */
    public function idsSince(string $date, int $limit, int $offset): array;
}

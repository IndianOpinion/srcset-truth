<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Encoding;

use IndianOpinion\SrcsetTruth\Exception\EncodeFailed;
use IndianOpinion\SrcsetTruth\Files\ImageType;
use IndianOpinion\SrcsetTruth\Files\Siblings;
use IndianOpinion\SrcsetTruth\Files\SkipLedger;
use IndianOpinion\SrcsetTruth\Files\Uploads;
use IndianOpinion\SrcsetTruth\Logger;
use IndianOpinion\SrcsetTruth\Settings;

/**
 * Creates one WebP sibling (PRD 4.6). Never called during a page request.
 *
 * Results: created, fresh (already there), skipped (by type, limit or a
 * matching ledger record), failed (with a reason code), missing (no source).
 */
final class SiblingCreator
{
    /** Number of encoder calls made in this process. */
    public static int $encodes = 0;

    /**
     * @return array{status: string, reason: ?string, sibling: string}
     */
    public static function create(string $source): array
    {
        $sibling = Siblings::path($source);
        $out = static fn (string $status, ?string $reason = null): array => ['status' => $status, 'reason' => $reason, 'sibling' => $sibling];

        if (!Uploads::contains($source)) {
            return $out('skipped', 'outside-uploads');
        }
        clearstatcache(true, $source);
        clearstatcache(true, $sibling);
        if (!is_file($source)) {
            return $out('missing');
        }
        $mime = ImageType::sniff($source);
        if ($mime === null || !in_array($mime, ImageType::SOURCE_TYPES, true)) {
            return $out('skipped', 'unsupported-type');
        }
        if (Siblings::state($source) === 'fresh') {
            return $out('fresh');
        }
        $recorded = SkipLedger::reason($source);
        if ($recorded !== null) {
            return $out($recorded === 'not-beneficial' ? 'skipped' : 'failed', $recorded);
        }
        if (!self::enabled()) {
            return $out('skipped', 'disabled');
        }

        $limits = Limits::get();
        $bytes = (int) filesize($source);
        if ($bytes > $limits['bytes']) {
            return self::fail($source, $sibling, 'too-large', true);
        }
        $info = getimagesize($source);
        if (!is_array($info) || $info[0] <= 0 || $info[1] <= 0) {
            return self::fail($source, $sibling, 'encode-error', true);
        }
        if ($info[0] * $info[1] > $limits['megapixels'] * 1000000) {
            return self::fail($source, $sibling, 'too-large', true);
        }
        $animated = false;
        if ($mime === 'image/gif') {
            $frames = ImageType::gifFrames($source);
            if ($frames > $limits['frames']) {
                return self::fail($source, $sibling, 'too-many-frames', true);
            }
            $animated = $frames > 1;
        }
        $encoder = EncoderFactory::pick($animated);
        if ($encoder === null) {
            // Environmental, not a property of the file: not recorded.
            return self::fail($source, $sibling, 'no-encoder', false);
        }
        $kind = $animated ? 'animated' : ($mime === 'image/gif' ? 'graphic' : 'photo');
        $quality = self::quality($kind, $mime);
        $colour = ImageType::colour($source, $mime);

        $temp = self::tempPath($sibling);
        try {
            self::$encodes++;
            $encoder->encode($source, $temp, $mime, $quality, $animated, $colour);
            clearstatcache(true, $temp);
            if (!is_file($temp) || filesize($temp) === 0) {
                throw new EncodeFailed('encode-error', 'The encoder wrote nothing.');
            }
            $siblingBytes = (int) filesize($temp);
            if ($siblingBytes >= $bytes) {
                self::unlink($temp);
                return self::fail($source, $sibling, 'not-beneficial', true);
            }
            $mode = defined('FS_CHMOD_FILE') ? (int) constant('FS_CHMOD_FILE') : 0644;
            chmod($temp, $mode & 0777);
            if (!rename($temp, $sibling)) {
                throw new EncodeFailed('write-error', 'Could not move the sibling into place.');
            }
        } catch (\Throwable $e) {
            self::unlink($temp);
            $reason = $e instanceof EncodeFailed ? $e->reason : 'encode-error';
            Logger::log(sprintf('Encode failed for %s: %s (%s).', Uploads::relative($source), $reason, $e->getMessage()));

            return self::fail($source, $sibling, $reason, $reason !== 'no-encoder');
        }
        Siblings::forget($source);
        do_action('srcset_truth_sibling_created', $source, $sibling, $bytes, $siblingBytes);

        return $out('created');
    }

    public static function enabled(): bool
    {
        return (bool) apply_filters('srcset_truth_webp_enabled', Settings::bool('webp'));
    }

    public static function quality(string $kind, string $mime): int
    {
        $base = match ($kind) {
            'animated' => 72,
            'graphic' => 80,
            default => (int) Settings::get('quality'),
        };
        $quality = apply_filters('srcset_truth_webp_quality', $base, $kind, $mime);

        return is_int($quality) && $quality >= 1 && $quality <= 100 ? $quality : $base;
    }

    /**
     * @return array{status: string, reason: ?string, sibling: string}
     */
    private static function fail(string $source, string $sibling, string $reason, bool $record): array
    {
        if ($record) {
            SkipLedger::record($source, $reason);
        }
        do_action('srcset_truth_sibling_failed', $source, $reason);

        return ['status' => $reason === 'not-beneficial' ? 'skipped' : 'failed', 'reason' => $reason, 'sibling' => $sibling];
    }

    private static function tempPath(string $sibling): string
    {
        return dirname($sibling) . '/.' . basename($sibling) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    }

    private static function unlink(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Remove the sibling of one original. Never touches the original.
     */
    public static function removeSibling(string $original): bool
    {
        $sibling = Siblings::path($original);
        if (!Siblings::hasSourceExtension($original) || !Uploads::contains($sibling) || !is_file($sibling)) {
            return false;
        }
        $removed = unlink($sibling);
        Siblings::forget($original);

        return $removed;
    }
}

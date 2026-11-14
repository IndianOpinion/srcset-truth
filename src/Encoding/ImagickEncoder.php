<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Encoding;

use IndianOpinion\SrcsetTruth\Exception\EncodeFailed;

/**
 * Imagick with WebP write support. Handles animation and keeps the colour
 * profile when one is embedded (PRD 27 to 30).
 */
final class ImagickEncoder implements Encoder
{
    public static function available(): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }
        try {
            return \Imagick::queryFormats('WEBP') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function name(): string
    {
        return 'imagick';
    }

    public function supportsAnimation(): bool
    {
        return true;
    }

    public function encode(string $source, string $destination, string $mime, int $quality, bool $animated, array $colour): void
    {
        $image = null;
        try {
            $image = new \Imagick();
            $image->readImage($source);
            if ($animated) {
                $frames = $image->coalesceImages();
                $image->clear();
                $image = $frames;
            }
            $icc = null;
            if ($colour['cmyk']) {
                $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            } elseif ($colour['icc']) {
                $profiles = $image->getImageProfiles('icc', true);
                $icc = isset($profiles['icc']) && is_string($profiles['icc']) ? $profiles['icc'] : null;
            }
            $iterations = $image->getImageIterations();
            foreach ($image as $frame) {
                // Strip EXIF, XMP and comments; the ICC profile is put back below.
                $frame->stripImage();
                if ($icc !== null) {
                    $frame->profileImage('icc', $icc);
                }
                $frame->setImageFormat('webp');
                $frame->setImageCompressionQuality($quality);
                $frame->setOption('webp:method', '4');
                if ($mime === 'image/png' || $mime === 'image/gif') {
                    $frame->setOption('webp:alpha-quality', '100');
                }
            }
            if ($animated) {
                $image->setImageIterations($iterations);
                $image->setOption('webp:lossless', 'false');
                $ok = $image->writeImages('webp:' . $destination, true);
            } else {
                $image->setIteratorIndex(0);
                $ok = $image->writeImage('webp:' . $destination);
            }
            if ($ok !== true) {
                throw new EncodeFailed('write-error', 'Imagick could not write the WebP file.');
            }
        } catch (EncodeFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EncodeFailed('encode-error', 'Imagick: ' . $e->getMessage(), $e);
        } finally {
            if ($image instanceof \Imagick) {
                $image->clear();
            }
        }
    }
}

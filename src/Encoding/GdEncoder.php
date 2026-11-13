<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Encoding;

use IndianOpinion\SrcsetTruth\Exception\EncodeFailed;

/**
 * GD with imagewebp. Static images only. GD drops colour profiles, so a
 * source with a non-sRGB profile or CMYK data is refused with
 * "colour-profile" rather than written with shifted colour (PRD 29).
 */
final class GdEncoder implements Encoder
{
    public static function available(): bool
    {
        if (!function_exists('imagewebp') || !function_exists('gd_info')) {
            return false;
        }
        $info = gd_info();

        return !empty($info['WebP Support']);
    }

    public function name(): string
    {
        return 'gd';
    }

    public function supportsAnimation(): bool
    {
        return false;
    }

    public function encode(string $source, string $destination, string $mime, int $quality, bool $animated, array $colour): void
    {
        if ($animated) {
            throw new EncodeFailed('no-encoder', 'GD cannot write animated WebP.');
        }
        if ($colour['cmyk'] || ($colour['icc'] && !$colour['srgb'])) {
            throw new EncodeFailed('colour-profile', 'GD cannot convert or keep a non-sRGB colour profile.');
        }
        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($source),
            'image/png' => imagecreatefrompng($source),
            'image/gif' => imagecreatefromgif($source),
            default => false,
        };
        if ($image === false) {
            throw new EncodeFailed('encode-error', 'GD could not decode the source.');
        }
        try {
            if (!imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }
            imagealphablending($image, false);
            imagesavealpha($image, true);
            if (!imagewebp($image, $destination, $quality)) {
                throw new EncodeFailed('write-error', 'GD could not write the WebP file.');
            }
        } finally {
            imagedestroy($image);
        }
    }
}

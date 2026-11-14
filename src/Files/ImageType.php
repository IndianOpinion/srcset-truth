<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Files;

/**
 * Real image type from the file signature, not the extension (PRD case 26),
 * plus the facts the encoders need: animation and colour profile.
 */
final class ImageType
{
    public const SOURCE_TYPES = ['image/jpeg', 'image/png', 'image/gif'];

    public static function sniff(string $path): ?string
    {
        $head = self::read($path, 0, 16);
        if ($head === null || strlen($head) < 4) {
            return null;
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (substr($head, 4, 4) === 'ftyp') {
            return 'image/avif';
        }
        if (str_starts_with(ltrim($head), '<')) {
            return 'image/svg+xml';
        }

        return null;
    }

    /**
     * Number of frames in a GIF, counted from graphic control extensions
     * without decoding. At least 1 for any readable GIF.
     */
    public static function gifFrames(string $path): int
    {
        $data = self::read($path, 0, null);
        if ($data === null) {
            return 0;
        }
        $count = preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $data);

        return max(1, (int) $count);
    }

    /**
     * Colour facts for the colour rule (PRD case 29).
     *
     * @return array{icc: bool, srgb: bool, cmyk: bool}
     */
    public static function colour(string $path, string $mime): array
    {
        $facts = ['icc' => false, 'srgb' => true, 'cmyk' => false];
        $info = getimagesize($path);
        if (is_array($info) && isset($info['channels']) && (int) $info['channels'] === 4 && $mime === 'image/jpeg') {
            $facts['cmyk'] = true;
            $facts['srgb'] = false;
        }
        $profile = $mime === 'image/jpeg' ? self::jpegIcc($path) : ($mime === 'image/png' ? self::pngIcc($path) : null);
        if ($profile !== null) {
            $facts['icc'] = true;
            $facts['srgb'] = !$facts['cmyk'] && stripos($profile, 'sRGB') !== false;
        }

        return $facts;
    }

    public static function jpegIcc(string $path): ?string
    {
        $data = self::read($path, 0, 2 * 1024 * 1024);
        if ($data === null) {
            return null;
        }
        $offset = 2;
        $length = strlen($data);
        $profile = '';
        while ($offset + 4 <= $length && $data[$offset] === "\xFF") {
            $marker = ord($data[$offset + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $size = (ord($data[$offset + 2]) << 8) | ord($data[$offset + 3]);
            if ($size < 2) {
                break;
            }
            if ($marker === 0xE2 && substr($data, $offset + 4, 12) === "ICC_PROFILE\0") {
                $profile .= substr($data, $offset + 18, $size - 16);
            }
            $offset += 2 + $size;
        }

        return $profile === '' ? null : $profile;
    }

    public static function pngIcc(string $path): ?string
    {
        $data = self::read($path, 0, 2 * 1024 * 1024);
        if ($data === null) {
            return null;
        }
        $offset = 8;
        $length = strlen($data);
        while ($offset + 8 <= $length) {
            $unpacked = unpack('N', substr($data, $offset, 4));
            $size = is_array($unpacked) ? (int) $unpacked[1] : 0;
            $type = substr($data, $offset + 4, 4);
            if ($type === 'sRGB') {
                return null;
            }
            if ($type === 'iCCP') {
                $chunk = substr($data, $offset + 8, $size);
                $nul = strpos($chunk, "\0");
                $name = $nul === false ? '' : substr($chunk, 0, $nul);
                $inflated = $nul === false ? false : gzuncompress(substr($chunk, $nul + 2));

                return $name . ' ' . (is_string($inflated) ? $inflated : '');
            }
            if ($type === 'IDAT' || $type === 'IEND') {
                return null;
            }
            $offset += 12 + $size;
        }

        return null;
    }

    private static function read(string $path, int $offset, ?int $length): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            if ($offset > 0) {
                fseek($handle, $offset);
            }
            $data = $length === null ? stream_get_contents($handle) : fread($handle, max(1, $length));
        } finally {
            fclose($handle);
        }

        return is_string($data) ? $data : null;
    }
}

<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Encoding;

use IndianOpinion\SrcsetTruth\Exception\EncodeFailed;

/**
 * Writes one WebP file from one source image.
 */
interface Encoder
{
    public function name(): string;

    public function supportsAnimation(): bool;

    /**
     * @param array{icc: bool, srgb: bool, cmyk: bool} $colour
     * @throws EncodeFailed
     */
    public function encode(string $source, string $destination, string $mime, int $quality, bool $animated, array $colour): void;
}

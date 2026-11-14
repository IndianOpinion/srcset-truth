<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Tests;

use IndianOpinion\SrcsetTruth\Encoding\Encoder;

/**
 * An encoder whose behaviour a test supplies.
 */
final class FakeEncoder implements Encoder
{
    public int $calls = 0;

    /** @var list<array{mime: string, quality: int, animated: bool}> */
    public array $seen = [];

    /**
     * @param \Closure(string, string): void $write
     */
    public function __construct(private \Closure $write, private bool $animation = true)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function supportsAnimation(): bool
    {
        return $this->animation;
    }

    public function encode(string $source, string $destination, string $mime, int $quality, bool $animated, array $colour): void
    {
        $this->calls++;
        $this->seen[] = ['mime' => $mime, 'quality' => $quality, 'animated' => $animated];
        ($this->write)($source, $destination);
    }
}

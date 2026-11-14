<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Encoding;

/**
 * Encoder choice (PRD 27): Imagick when it can write WebP, otherwise GD for
 * static images, otherwise none.
 */
final class EncoderFactory
{
    /** @var list<Encoder>|null */
    private static ?array $override = null;

    /**
     * Encoders in order of preference. Empty when none is available.
     *
     * @return list<Encoder>
     */
    public static function available(): array
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $encoders = [];
        if (ImagickEncoder::available()) {
            $encoders[] = new ImagickEncoder();
        }
        if (GdEncoder::available()) {
            $encoders[] = new GdEncoder();
        }

        return $encoders;
    }

    public static function pick(bool $animated): ?Encoder
    {
        foreach (self::available() as $encoder) {
            if (!$animated || $encoder->supportsAnimation()) {
                return $encoder;
            }
        }

        return null;
    }

    public static function describe(): string
    {
        $names = array_map(static fn (Encoder $e): string => $e->name(), self::available());

        return $names === [] ? 'none' : implode(', ', $names);
    }

    /**
     * Replace detection (tests, or a site that wants one encoder only).
     *
     * @param list<Encoder>|null $encoders
     */
    public static function override(?array $encoders): void
    {
        self::$override = $encoders;
    }
}

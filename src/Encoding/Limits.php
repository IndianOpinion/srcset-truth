<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Encoding;

/**
 * Decoder safety limits (PRD 30, 34).
 */
final class Limits
{
    /**
     * @return array{megapixels: float, bytes: int, frames: int}
     */
    public static function get(): array
    {
        $defaults = ['megapixels' => 40.0, 'bytes' => 30 * 1024 * 1024, 'frames' => 300];
        $limits = apply_filters('srcset_truth_limits', $defaults);
        if (!is_array($limits)) {
            return $defaults;
        }

        $positive = static fn (string $key): bool => isset($limits[$key]) && is_numeric($limits[$key]) && $limits[$key] > 0;

        return [
            'megapixels' => $positive('megapixels') ? (float) $limits['megapixels'] : $defaults['megapixels'],
            'bytes' => $positive('bytes') ? (int) $limits['bytes'] : $defaults['bytes'],
            'frames' => $positive('frames') ? (int) $limits['frames'] : $defaults['frames'],
        ];
    }
}

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

        return [
            'megapixels' => isset($limits['megapixels']) && is_numeric($limits['megapixels']) && $limits['megapixels'] > 0 ? (float) $limits['megapixels'] : $defaults['megapixels'],
            'bytes' => isset($limits['bytes']) && is_numeric($limits['bytes']) && $limits['bytes'] > 0 ? (int) $limits['bytes'] : $defaults['bytes'],
            'frames' => isset($limits['frames']) && is_numeric($limits['frames']) && $limits['frames'] > 0 ? (int) $limits['frames'] : $defaults['frames'],
        ];
    }
}

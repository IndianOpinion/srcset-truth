<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Audit;

use IndianOpinion\SrcsetTruth\Markup\Srcset;

/**
 * Which candidate a browser would pick (PRD 77).
 *
 * The rule used is the specification's: the smallest candidate whose
 * density (width / slot) is at least the device pixel ratio, else the
 * largest. Real browsers may pick a slightly smaller neighbour; the audit
 * states the rule so its numbers can be checked by hand.
 */
final class Picker
{
    /**
     * @param list<array{url: string, descriptor: string}> $candidates
     */
    public static function pick(array $candidates, float $slot, float $dpr): ?int
    {
        if ($candidates === []) {
            return null;
        }
        $densities = [];
        foreach ($candidates as $i => $candidate) {
            $width = Srcset::width($candidate['descriptor']);
            if ($width !== null) {
                if ($slot <= 0) {
                    return null;
                }
                $densities[$i] = $width / $slot;
                continue;
            }
            $density = Srcset::density($candidate['descriptor']);
            if ($density === null) {
                return null;
            }
            $densities[$i] = $density;
        }
        $best = null;
        $largest = null;
        foreach ($densities as $i => $density) {
            if ($largest === null || $density > $densities[$largest]) {
                $largest = $i;
            }
            if ($density + 1e-9 >= $dpr && ($best === null || $density < $densities[$best])) {
                $best = $i;
            }
        }

        return $best ?? $largest;
    }
}

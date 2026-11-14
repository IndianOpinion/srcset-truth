<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Exception;

/**
 * Slot names are lower case letters, digits and hyphens, 1 to 40 characters.
 */
final class InvalidSlotName extends \InvalidArgumentException implements SrcsetTruthException
{
}

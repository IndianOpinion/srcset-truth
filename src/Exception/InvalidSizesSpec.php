<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Exception;

/**
 * A sizes spec (string or breakpoint list) failed the grammar in PRD case 22.
 */
final class InvalidSizesSpec extends \InvalidArgumentException implements SrcsetTruthException
{
}

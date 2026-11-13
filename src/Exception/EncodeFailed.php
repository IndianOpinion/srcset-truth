<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Exception;

/**
 * An encoder could not produce a sibling. Carries one of the reason codes.
 */
final class EncodeFailed extends \RuntimeException implements SrcsetTruthException
{
    public function __construct(public readonly string $reason, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }
}

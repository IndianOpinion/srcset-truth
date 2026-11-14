<?php

declare(strict_types=1);

namespace IndianOpinion\SrcsetTruth\Exception;

/**
 * The audit could not fetch or read the page. The CLI exits 1.
 */
final class AuditFailed extends \RuntimeException implements SrcsetTruthException
{
}

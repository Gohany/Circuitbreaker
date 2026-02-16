<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Deciders;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryDeciderInterface;

final class AlwaysRetryDecider implements RetryDeciderInterface
{
    public function shouldRetry(AttemptOutcomeInterface $outcome, AttemptContextInterface $context): bool
    {
        return $outcome->error() !== null;
    }
}
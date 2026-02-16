<?php declare(strict_types=1);

namespace Gohany\Retry;

interface RetryDeciderInterface
{
    public function shouldRetry(AttemptOutcomeInterface $outcome, AttemptContextInterface $context): bool;
}

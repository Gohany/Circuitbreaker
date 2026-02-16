<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Rtry;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryDeciderInterface;

final class CompositeDecider implements RetryDeciderInterface
{
    /** @var RetryDeciderInterface[] */
    private array $deciders;

    /**
     * @param RetryDeciderInterface ...$deciders
     */
    public function __construct(RetryDeciderInterface ...$deciders)
    {
        $this->deciders = $deciders;
    }

    public function shouldRetry(AttemptOutcomeInterface $outcome, AttemptContextInterface $context): bool
    {
        if ($outcome->isSuccess()) {
            return false;
        }

        foreach ($this->deciders as $decider) {
            if (!$decider->shouldRetry($outcome, $context)) {
                return false;
            }
        }

        return true;
    }
}

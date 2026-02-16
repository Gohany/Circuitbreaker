<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Rtry;

use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryDeciderInterface;

final class CircuitHealthRetryDecider implements RetryDeciderInterface
{
    private CircuitBreakerInterface $breaker;
    private CircuitKey $key;
    private CircuitContext $context;

    public function __construct(CircuitBreakerInterface $breaker, CircuitKey $key, CircuitContext $context)
    {
        $this->breaker = $breaker;
        $this->key = $key;
        $this->context = $context;
    }

    public function shouldRetry(AttemptOutcomeInterface $outcome, AttemptContextInterface $context): bool
    {
        // If the circuit is no longer allowed, stop retrying immediately.
        $decision = $this->breaker->decide($this->key, $this->context);
        return $decision->allowed;
    }
}

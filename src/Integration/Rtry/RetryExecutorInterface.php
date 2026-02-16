<?php

namespace Gohany\Circuitbreaker\Integration\Rtry;

use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;

interface RetryExecutorInterface
{
    /**
     * @param mixed $retryPolicyOrSpec
     * @return mixed
     */
    public function try(CircuitBreakerInterface $breaker, CircuitKey $key, CircuitContext $context, callable $operation, $retryPolicyOrSpec);
}

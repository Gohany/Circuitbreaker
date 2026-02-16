<?php

declare(strict_types=1);

namespace tests\TestDoubles;

use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Integration\Rtry\RetryExecutorInterface;

final class FakeRetryExecutor implements RetryExecutorInterface
{
    public int $calls = 0;

    public function try(CircuitBreakerInterface $breaker, CircuitKey $key, CircuitContext $context, callable $operation, $retryPolicyOrSpec)
    {
        $this->calls++;
        return $operation();
    }
}

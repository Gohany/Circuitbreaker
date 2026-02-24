<?php

declare(strict_types=1);

namespace tests\Resilience;

use Gohany\Circuitbreaker\Exception\CircuitOpenException;
use Gohany\Circuitbreaker\Resilience\CircuitBreakerConfig;
use Gohany\Circuitbreaker\Resilience\InMemoryCircuitBreaker;
use PHPUnit\Framework\TestCase;

final class InMemoryCircuitBreakerTest extends TestCase
{
    public function testClosedToOpenOnFailureRate(): void
    {
        $cfg = new CircuitBreakerConfig();
        $cfg->minimumCalls = 1;
        $cfg->failureRateToOpen = 1.0;

        $cb = new InMemoryCircuitBreaker('c1', $cfg);

        $cb->recordFailure('op', new \RuntimeException('fail'));

        $this->assertSame(InMemoryCircuitBreaker::STATE_OPEN, $cb->getState('op'));
    }

    public function testOpenTransitionsToHalfOpenAfterOpenDurationAndEnforcesHalfOpenConcurrency(): void
    {
        $cfg = new CircuitBreakerConfig();
        $cfg->minimumCalls = 1;
        $cfg->failureRateToOpen = 1.0;
        $cfg->openDurationSeconds = 0.0; // immediate half-open on next permission check
        $cfg->halfOpenStartFraction = 1.0;
        $cfg->halfOpenRampDurationSeconds = 0.0;
        $cfg->halfOpenMaxConcurrent = 2;

        $cb = new InMemoryCircuitBreaker('c1', $cfg);
        $cb->recordFailure('op', new \RuntimeException('fail'));

        // First acquire transitions open -> half_open and consumes a half-open permit.
        $cb->acquirePermission('op');
        $this->assertSame(InMemoryCircuitBreaker::STATE_HALF_OPEN, $cb->getState('op'));

        // Second half-open permit allowed.
        $cb->acquirePermission('op');

        // Third should be rejected due to half-open capacity.
        $this->expectException(CircuitOpenException::class);
        $this->expectExceptionMessage('half-open capacity');
        $cb->acquirePermission('op');
    }

    public function testHalfOpenSuccessCanCloseCircuitWhenFullyRampedAndNoHalfOpenFailures(): void
    {
        $cfg = new CircuitBreakerConfig();
        $cfg->minimumCalls = 1;
        $cfg->failureRateToOpen = 1.0;
        $cfg->openDurationSeconds = 0.0;
        $cfg->halfOpenStartFraction = 1.0;
        $cfg->halfOpenRampDurationSeconds = 0.0;

        $cb = new InMemoryCircuitBreaker('c1', $cfg);
        $cb->recordFailure('op', new \RuntimeException('fail'));

        $cb->acquirePermission('op');
        $this->assertSame(InMemoryCircuitBreaker::STATE_HALF_OPEN, $cb->getState('op'));

        $cb->recordSuccess('op', 0.001);
        $this->assertSame(InMemoryCircuitBreaker::STATE_CLOSED, $cb->getState('op'));
    }

    public function testHalfOpenFailuresReopenCircuit(): void
    {
        $cfg = new CircuitBreakerConfig();
        $cfg->minimumCalls = 1;
        $cfg->failureRateToOpen = 1.0;
        $cfg->openDurationSeconds = 0.0;
        $cfg->halfOpenStartFraction = 1.0;
        $cfg->halfOpenRampDurationSeconds = 0.0;
        $cfg->halfOpenFailuresToOpen = 1;

        $cb = new InMemoryCircuitBreaker('c1', $cfg);
        $cb->recordFailure('op', new \RuntimeException('fail'));

        $cb->acquirePermission('op');
        $this->assertSame(InMemoryCircuitBreaker::STATE_HALF_OPEN, $cb->getState('op'));

        $cb->recordFailure('op', new \RuntimeException('half-open fail'));
        $this->assertSame(InMemoryCircuitBreaker::STATE_OPEN, $cb->getState('op'));
    }
}

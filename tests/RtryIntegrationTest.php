<?php

declare(strict_types=1);

namespace tests;

require_once __DIR__ . '/RtryTestDoubles.php';

use Gohany\Circuitbreaker\Exception\CircuitDeniedException;
use PHPUnit\Framework\TestCase;
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\PolicyDecision;
use Gohany\Circuitbreaker\Policy\TransitionPlan;
use Gohany\Circuitbreaker\Store\CircuitHistory;
use Gohany\Circuitbreaker\Store\CircuitSnapshot;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Store\CircuitStateStoreInterface;
use Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface;
use Gohany\Circuitbreaker\Integration\Rtry\RtryRetryExecutor;
use Gohany\Circuitbreaker\Integration\Rtry\RetrySpec;
use Gohany\Circuitbreaker\Integration\Rtry\RetrySpecProviderInterface;
use Gohany\Rtry\Impl\RtryPolicy;
use tests\TestDoubles\ClassifierStub;
use tests\TestDoubles\FakeProbeGate;
use tests\TestDoubles\FakePsrClock;
use tests\TestDoubles\FakeSideEffectDispatcher;
use tests\TestDoubles\ProbePolicyStub;

final class RtryIntegrationTest extends TestCase
{
    public function testRtryRetryExecutorCapturesAttemptsAndSignalsWeakerSuccess(): void
    {
        $clock = new FakePsrClock(10000);
        $stateStore = $this->createMock(CircuitStateStoreInterface::class);
        $stateStore->method('getState')->willReturn(new CircuitState(CircuitStateMode::CLOSED, null, 0, []));
        
        $historyStore = $this->createMock(CircuitHistoryStoreInterface::class);
        $historyStore->method('getHistory')->willReturn(new CircuitHistory([], []));

        $classifier = new TestOutcomeClassifier();

        $policy = new ProbePolicyStub(
            new PolicyDecision(true, 'ok', null, [], false),
            new \Gohany\Circuitbreaker\Store\ProbeGateConfig(1, true),
            new TransitionPlan(null, null, [], [])
        );

        $retryExecutor = new RtryRetryExecutor($classifier);
        // Retry 3 times on 'transient'
        $rtryPolicy = new RtryPolicy(3);
        $rtryPolicy->setStartAfterMs(0); // no delay for tests
        $rtryPolicy->setBackoffMode('exp');
        $rtryPolicy->setExponentialBase(1.0);

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            $classifier,
            [],
            new FakeSideEffectDispatcher(),
            $clock,
            new FakeProbeGate(),
            $retryExecutor,
            $rtryPolicy
        );

        $key = new CircuitKey('svc', []);
        $ctx = new CircuitContext('user1', [], []);

        $calls = 0;
        $result = $breaker->execute($key, $ctx, function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new \RuntimeException('transient');
            }
            return 'recovered';
        });

        $this->assertSame('recovered', $result);
        $this->assertSame(3, $calls);
        
        $this->assertGreaterThanOrEqual(3, count($classifier->capturedContexts));
        $finalContext = end($classifier->capturedContexts);
        $this->assertSame(3, $finalContext['retry_attempts']);
    }

    public function testRetrySpecProviderIntegration(): void
    {
        $clock = new FakePsrClock(10000);
        $stateStore = $this->createMock(CircuitStateStoreInterface::class);
        $stateStore->method('getState')->willReturn(new CircuitState(CircuitStateMode::CLOSED, null, 0, []));
        $historyStore = $this->createMock(CircuitHistoryStoreInterface::class);
        $historyStore->method('getHistory')->willReturn(new CircuitHistory([], []));

        $classifier = new TestOutcomeClassifier();

        $rtryPolicy = new RtryPolicy(2);
        $spec = new RetrySpec($rtryPolicy);

        $policy = new TestRetrySpecProviderPolicy($spec);

        $retryExecutor = new RtryRetryExecutor($classifier);

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            $classifier,
            [],
            new FakeSideEffectDispatcher(),
            $clock,
            new FakeProbeGate(),
            $retryExecutor,
            null // no default spec
        );

        $key = new CircuitKey('svc', []);
        $ctx = new CircuitContext('user1', [], []);

        $calls = 0;
        $result = $breaker->execute($key, $ctx, function () use (&$calls) {
            $calls++;
            if ($calls < 2) {
                throw new \RuntimeException('transient');
            }
            return 'ok';
        });

        $this->assertSame(2, $calls);
        $this->assertSame('ok', $result);
    }

    public function testRetriesAbortIfCircuitIsDeniedMidRetry(): void
    {
        $clock = new FakePsrClock(10000);
        $stateStore = $this->createMock(CircuitStateStoreInterface::class);
        $stateStore->method('getState')->willReturn(new CircuitState(CircuitStateMode::CLOSED, null, 0, []));
        $historyStore = $this->createMock(CircuitHistoryStoreInterface::class);
        $historyStore->method('getHistory')->willReturn(new CircuitHistory([], []));

        $classifier = new TestOutcomeClassifier();

        $policy = new ProbePolicyStub(
            new PolicyDecision(true, 'ok', null, [], false),
            new \Gohany\Circuitbreaker\Store\ProbeGateConfig(1, true),
            new TransitionPlan(null, null, [], [])
        );

        // This override decider will deny the circuit after the first call
        $override = new class implements \Gohany\Circuitbreaker\Override\OverrideDeciderInterface {
            public int $calls = 0;
            public function decide(\Gohany\Circuitbreaker\Core\CircuitKey $key, \Gohany\Circuitbreaker\Core\CircuitContext $context): ?\Gohany\Circuitbreaker\Override\OverrideDecision {
                if ($this->calls >= 1) {
                    return new \Gohany\Circuitbreaker\Override\OverrideDecision(false, 'denied_mid_retry');
                }
                return null;
            }
        };

        $retryExecutor = new RtryRetryExecutor($classifier);
        $rtryPolicy = new RtryPolicy(5);
        $rtryPolicy->setStartAfterMs(0);
        $rtryPolicy->setBackoffMode('exp');
        $rtryPolicy->setExponentialBase(1.0);

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            $classifier,
            [$override],
            new FakeSideEffectDispatcher(),
            $clock,
            new FakeProbeGate(),
            $retryExecutor,
            $rtryPolicy
        );

        $key = new CircuitKey('svc', []);
        $ctx = new CircuitContext('user1', [], []);

        $calls = 0;
        $this->expectException(CircuitDeniedException::class);
        $this->expectExceptionMessage('Circuit denied: denied_mid_retry');

        try {
            $breaker->execute($key, $ctx, function () use (&$calls, $override) {
                $calls++;
                $override->calls++; // Signal the override to deny on next check
                throw new \RuntimeException('transient');
            });
        } finally {
            // It should have only called once because the circuit health decider aborted retries
            $this->assertSame(1, $calls);
        }
    }

    public function testExistingRtryDeciderIsRespected(): void
    {
        $clock = new FakePsrClock(10000);
        $stateStore = $this->createMock(CircuitStateStoreInterface::class);
        $stateStore->method('getState')->willReturn(new CircuitState(CircuitStateMode::CLOSED, null, 0, []));
        $historyStore = $this->createMock(CircuitHistoryStoreInterface::class);
        $historyStore->method('getHistory')->willReturn(new CircuitHistory([], []));

        $classifier = new TestOutcomeClassifier();

        // Custom decider that always says NO to retries
        $customDecider = new class implements \Gohany\Retry\RetryDeciderInterface {
            public function shouldRetry(\Gohany\Retry\AttemptOutcomeInterface $outcome, \Gohany\Retry\AttemptContextInterface $context): bool {
                return false;
            }
        };
        
        $rtryPolicy = new RtryPolicy(3);
        $rtryPolicy->setStartAfterMs(0);
        $rtryPolicy->setRetryDecider($customDecider);

        $retryExecutor = new RtryRetryExecutor($classifier);

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            new ProbePolicyStub(
                new PolicyDecision(true, 'ok', null, [], false),
                new \Gohany\Circuitbreaker\Store\ProbeGateConfig(1, true),
                new TransitionPlan(null, null, [], [])
            ),
            $classifier,
            [],
            new FakeSideEffectDispatcher(),
            $clock,
            new FakeProbeGate(),
            $retryExecutor,
            $rtryPolicy
        );

        $key = new CircuitKey('svc', []);
        $ctx = new CircuitContext('user1', [], []);

        $calls = 0;
        try {
            $breaker->execute($key, $ctx, function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('transient'); 
            });
        } catch (\Throwable $e) {
            $this->assertSame('transient', $e->getMessage());
        }

        // Should NOT have retried because custom decider said no, 
        // even though classifier would have said yes.
        $this->assertSame(1, $calls);
    }
}

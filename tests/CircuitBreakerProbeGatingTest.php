<?php

declare(strict_types=1);

namespace tests;

use Gohany\Circuitbreaker\Exception\ProbeGateBlockedException;
use PHPUnit\Framework\TestCase;
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Policy\PolicyDecision;
use Gohany\Circuitbreaker\Policy\TransitionPlan;
use Gohany\Circuitbreaker\Store\CircuitHistory;
use Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface;
use Gohany\Circuitbreaker\Store\CircuitSnapshot;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\CircuitStateStoreInterface;
use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use Gohany\Circuitbreaker\SideEffect\SideEffectRequest;
use tests\TestDoubles\ClassifierStub;
use tests\TestDoubles\FakeProbeGate;
use tests\TestDoubles\FakePsrClock;
use tests\TestDoubles\FakeRetryExecutor;
use tests\TestDoubles\FakeSideEffectDispatcher;
use tests\TestDoubles\ProbePolicyStub;

final class CircuitBreakerProbeGatingTest extends TestCase
{
    public function testProbeGateUsesPolicyProvidedConfigAndReleases(): void
    {
        $clock = new FakePsrClock(10_000);

        $stateStore = new class implements CircuitStateStoreInterface {
            public function getState(CircuitKey $key): CircuitState { return new CircuitState(CircuitStateMode::HALF_OPEN, null, 0, ['version' => 0]); }
            public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool { return true; }
        };

        $historyStore = new class implements CircuitHistoryStoreInterface {
            public function getHistory(CircuitKey $key): CircuitHistory { return new CircuitHistory([], []); }
            public function record(CircuitKey $key, \Gohany\Circuitbreaker\Store\HistoryRecord $record): void {}
        };

        $decision = new PolicyDecision(true, 'half_open_probe', null, [], true);
        $probeConfig = new ProbeGateConfig(3, true);
        $plan = new TransitionPlan(null, null, [], []);
        $policy = new ProbePolicyStub($decision, $probeConfig, $plan);

        $probeGate = new FakeProbeGate();
        $probeGate->allowAcquire = true;

        $retry = new FakeRetryExecutor();
        $sideEffects = new FakeSideEffectDispatcher();

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            new ClassifierStub(true),
            [],
            $sideEffects,
            $clock,
            $probeGate,
            $retry,
            'rtry:spec'
        );

        $key = new CircuitKey('svc', []);
        $ctx = new CircuitContext('22542', [], []);

        $result = $breaker->execute($key, $ctx, function () {
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(1, $probeGate->acquireCalls);
        $this->assertSame(1, $probeGate->releaseCalls);
        $this->assertSame(3, $probeGate->configs[0]->maxInFlight);
        $this->assertSame(1, $retry->calls);
    }

    public function testProbeGateBlocksExecutionWhenAcquireFails(): void
    {
        $clock = new FakePsrClock(10_000);

        $stateStore = new class implements CircuitStateStoreInterface {
            public function getState(CircuitKey $key): CircuitState { return new CircuitState(CircuitStateMode::HALF_OPEN, null, 0, ['version' => 0]); }
            public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool { return true; }
        };

        $historyStore = new class implements CircuitHistoryStoreInterface {
            public function getHistory(CircuitKey $key): CircuitHistory { return new CircuitHistory([], []); }
            public function record(CircuitKey $key, \Gohany\Circuitbreaker\Store\HistoryRecord $record): void {}
        };

        $decision = new PolicyDecision(true, 'half_open_probe', null, [], true);
        $probeConfig = new ProbeGateConfig(1, true);
        $plan = new TransitionPlan(null, null, [], []);
        $policy = new ProbePolicyStub($decision, $probeConfig, $plan);

        $probeGate = new FakeProbeGate();
        $probeGate->allowAcquire = false;
        $probeGate->retryAfterMs = 250;

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            new ClassifierStub(true),
            [],
            new FakeSideEffectDispatcher(),
            $clock,
            $probeGate,
            null,
            null
        );

        $this->expectException(ProbeGateBlockedException::class);

        $breaker->execute(new CircuitKey('svc', []), new CircuitContext(null, [], []), function () {
            return 'ok';
        });

        $this->assertSame(1, $probeGate->acquireCalls);
        $this->assertSame(0, $probeGate->releaseCalls);
    }

    public function testProbeGateIsReleasedEvenWhenOperationThrows(): void
    {
        $clock = new FakePsrClock(10_000);

        $stateStore = new class implements CircuitStateStoreInterface {
            public function getState(CircuitKey $key): CircuitState { return new CircuitState(CircuitStateMode::HALF_OPEN, null, 0, ['version' => 0]); }
            public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool { return true; }
        };

        $historyStore = new class implements CircuitHistoryStoreInterface {
            public function getHistory(CircuitKey $key): CircuitHistory { return new CircuitHistory([], []); }
            public function record(CircuitKey $key, \Gohany\Circuitbreaker\Store\HistoryRecord $record): void {}
        };

        $decision = new PolicyDecision(true, 'half_open_probe', null, [], true);
        $probeConfig = new ProbeGateConfig(1, true);
        $plan = new TransitionPlan(null, null, [], []);
        $policy = new ProbePolicyStub($decision, $probeConfig, $plan);

        $probeGate = new FakeProbeGate();
        $probeGate->allowAcquire = true;

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            new ClassifierStub(false),
            [],
            new FakeSideEffectDispatcher(),
            $clock,
            $probeGate,
            null,
            null
        );

        $this->expectException(\RuntimeException::class);

        try {
            $breaker->execute(new CircuitKey('svc', []), new CircuitContext(null, [], []), function () {
                throw new \RuntimeException('boom');
            });
        } finally {
            $this->assertSame(1, $probeGate->acquireCalls);
            $this->assertSame(1, $probeGate->releaseCalls);
        }
    }

    public function testProbeGateIsReleasedEvenIfClassifierThrows(): void
    {
        $clock = new FakePsrClock(10_000);

        $stateStore = new class implements CircuitStateStoreInterface {
            public function getState(CircuitKey $key): CircuitState { return new CircuitState(CircuitStateMode::HALF_OPEN, null, 0, ['version' => 0]); }
            public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool { return true; }
        };

        $historyStore = new class implements CircuitHistoryStoreInterface {
            public function getHistory(CircuitKey $key): CircuitHistory { return new CircuitHistory([], []); }
            public function record(CircuitKey $key, \Gohany\Circuitbreaker\Store\HistoryRecord $record): void {}
        };

        $decision = new PolicyDecision(true, 'half_open_probe', null, [], true);
        $probeConfig = new ProbeGateConfig(1, true);
        $plan = new TransitionPlan(null, null, [], []);
        $policy = new ProbePolicyStub($decision, $probeConfig, $plan);

        $probeGate = new FakeProbeGate();
        $probeGate->allowAcquire = true;

        $classifier = new class implements \Gohany\Circuitbreaker\Policy\OutcomeClassifierInterface {
            public function classify($result, $error, array $context = []): \Gohany\Circuitbreaker\Policy\CircuitOutcome
            {
                throw new \RuntimeException('classifier blew up');
            }
        };

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            $classifier,
            [],
            new FakeSideEffectDispatcher(),
            $clock,
            $probeGate,
            null,
            null
        );

        $this->expectException(\RuntimeException::class);

        try {
            $breaker->execute(new CircuitKey('svc', []), new CircuitContext(null, [], []), function () {
                return 'ok';
            });
        } finally {
            $this->assertSame(1, $probeGate->acquireCalls);
            $this->assertSame(1, $probeGate->releaseCalls);
        }
    }

    public function testProbeGateIsReleasedEvenIfPolicyOnOutcomeThrows(): void
    {
        $clock = new FakePsrClock(10_000);

        $stateStore = new class implements CircuitStateStoreInterface {
            public function getState(CircuitKey $key): CircuitState { return new CircuitState(CircuitStateMode::HALF_OPEN, null, 0, ['version' => 0]); }
            public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool { return true; }
        };

        $historyStore = new class implements CircuitHistoryStoreInterface {
            public function getHistory(CircuitKey $key): CircuitHistory { return new CircuitHistory([], []); }
            public function record(CircuitKey $key, \Gohany\Circuitbreaker\Store\HistoryRecord $record): void {}
        };

        $decision = new PolicyDecision(true, 'half_open_probe', null, [], true);
        $probeConfig = new ProbeGateConfig(1, true);

        $policy = new class($decision, $probeConfig) implements \Gohany\Circuitbreaker\Policy\CircuitPolicyInterface, \Gohany\Circuitbreaker\Policy\ProbeGateConfigProviderInterface {
            private PolicyDecision $decision;
            private ProbeGateConfig $config;

            public function __construct(PolicyDecision $decision, ProbeGateConfig $config)
            {
                $this->decision = $decision;
                $this->config = $config;
            }

            public function name() { return 'throwing_policy'; }

            public function decide(CircuitKey $key, CircuitContext $context, CircuitSnapshot $snapshot): PolicyDecision
            {
                return $this->decision;
            }

            public function onOutcome(CircuitKey $key, CircuitContext $context, \Gohany\Circuitbreaker\Policy\CircuitOutcome $outcome, CircuitSnapshot $snapshot): TransitionPlan
            {
                throw new \RuntimeException('policy onOutcome blew up');
            }

            public function probeGateConfig(CircuitKey $key, CircuitContext $context, CircuitSnapshot $snapshot, PolicyDecision $decision): ProbeGateConfig
            {
                return $this->config;
            }
        };

        $probeGate = new FakeProbeGate();
        $probeGate->allowAcquire = true;

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            new ClassifierStub(true),
            [],
            new FakeSideEffectDispatcher(),
            $clock,
            $probeGate,
            null,
            null
        );

        $this->expectException(\RuntimeException::class);

        try {
            $breaker->execute(new CircuitKey('svc', []), new CircuitContext(null, [], []), function () {
                return 'ok';
            });
        } finally {
            $this->assertSame(1, $probeGate->acquireCalls);
            $this->assertSame(1, $probeGate->releaseCalls);
        }
    }

    public function testProbeGateIsReleasedEvenIfApplyPlanThrows(): void
    {
        $clock = new FakePsrClock(10_000);

        $stateStore = new class implements CircuitStateStoreInterface {
            public function getState(CircuitKey $key): CircuitState { return new CircuitState(CircuitStateMode::HALF_OPEN, null, 0, ['version' => 0]); }
            public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool { return true; }
        };

        $historyStore = new class implements CircuitHistoryStoreInterface {
            public function getHistory(CircuitKey $key): CircuitHistory { return new CircuitHistory([], []); }
            public function record(CircuitKey $key, \Gohany\Circuitbreaker\Store\HistoryRecord $record): void {}
        };

        $decision = new PolicyDecision(true, 'half_open_probe', null, [], true);
        $probeConfig = new ProbeGateConfig(1, true);

        $sideEffect = new SideEffectRequest('payments', 'test_side_effect', ['foo' => 'bar'], []);
        $plan = new TransitionPlan(null, null, [$sideEffect], []);

        $policy = new ProbePolicyStub($decision, $probeConfig, $plan);

        $probeGate = new FakeProbeGate();
        $probeGate->allowAcquire = true;

        $throwingSideEffects = new class implements \Gohany\Circuitbreaker\SideEffect\SideEffectDispatcherInterface {
            public function dispatch(SideEffectRequest $request)
            {
                throw new \RuntimeException('side effect dispatch failed');
            }
        };

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            new ClassifierStub(true),
            [],
            $throwingSideEffects,
            $clock,
            $probeGate,
            null,
            null
        );

        $this->expectException(\RuntimeException::class);

        try {
            $breaker->execute(new CircuitKey('svc', []), new CircuitContext(null, [], []), function () {
                return 'ok';
            });
        } finally {
            $this->assertSame(1, $probeGate->acquireCalls);
            $this->assertSame(1, $probeGate->releaseCalls);
        }
    }
}

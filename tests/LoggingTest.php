<?php

declare(strict_types=1);

namespace tests;

use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Exception\CircuitDeniedException;
use Gohany\Circuitbreaker\Exception\ProbeGateBlockedException;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\PolicyDecision;
use Gohany\Circuitbreaker\Policy\TransitionPlan;
use Gohany\Circuitbreaker\Store\CircuitHistory;
use Gohany\Circuitbreaker\Store\CircuitState;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use tests\TestDoubles\ClassifierStub;
use tests\TestDoubles\FakeProbeGate;
use tests\TestDoubles\FakePsrClock;
use tests\TestDoubles\ProbePolicyStub;

final class LoggingTest extends TestCase
{
    public function testLogsEmergencyWhenCircuitOpens(): void
    {
        $logger = new TestLogger();
        $clock = new FakePsrClock(10_000);
        
        $stateStore = $this->createMock(\Gohany\Circuitbreaker\Store\CircuitStateStoreInterface::class);
        $stateStore->method('getState')->willReturn(new CircuitState(CircuitStateMode::CLOSED, null, 0, []));
        $historyStore = $this->createMock(\Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface::class);
        $historyStore->method('getHistory')->willReturn(new CircuitHistory([], []));

        // Policy that triggers an OPEN transition
        $newState = new CircuitState(CircuitStateMode::OPEN, 20_000, 0, []);
        $plan = new TransitionPlan($newState, null, [], ['reason' => 'test_failure']);
        $policy = new ProbePolicyStub(
            new PolicyDecision(true, 'ok'),
            new \Gohany\Circuitbreaker\Store\ProbeGateConfig(1, true),
            $plan
        );

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            new ClassifierStub(false), // failure
            [],
            null,
            $clock,
            null,
            null,
            null,
            $logger
        );

        try {
            $breaker->execute(new CircuitKey('test_svc'), new CircuitContext(null), function() {
                throw new \RuntimeException('fail');
            });
        } catch (\Throwable $e) {}

        $this->assertTrue($logger->hasLog('emergency', 'Circuit state transition: open'));
        $this->assertTrue($logger->hasLog('error', 'Circuit operation failed: fail'));
        $this->assertSame('test_svc', $logger->logs[0]['context']['circuit']);
    }

    public function testLogsWarningWhenAccessDenied(): void
    {
        $logger = new TestLogger();
        $stateStore = $this->createMock(\Gohany\Circuitbreaker\Store\CircuitStateStoreInterface::class);
        $stateStore->method('getState')->willReturn(new CircuitState(CircuitStateMode::OPEN, 20_000, 0, []));
        $historyStore = $this->createMock(\Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface::class);
        $historyStore->method('getHistory')->willReturn(new \Gohany\Circuitbreaker\Store\CircuitHistory([], []));

        $policy = new ProbePolicyStub(
            new PolicyDecision(false, 'circuit_is_open', 10000),
            new \Gohany\Circuitbreaker\Store\ProbeGateConfig(1, true),
            new TransitionPlan(null, null, [], [])
        );

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            new ClassifierStub(true),
            [],
            null,
            null,
            null,
            null,
            null,
            $logger
        );

        $this->expectException(CircuitDeniedException::class);
        $breaker->execute(new CircuitKey('test_svc'), new CircuitContext(null), function() { return 'ok'; });

        $this->assertTrue($logger->hasLog('warning', 'Circuit denied access: circuit_is_open'));
    }

    public function testLogsWarningWhenProbeGateBlocked(): void
    {
        $logger = new TestLogger();
        $stateStore = $this->createMock(\Gohany\Circuitbreaker\Store\CircuitStateStoreInterface::class);
        $stateStore->method('getState')->willReturn(new CircuitState(CircuitStateMode::HALF_OPEN, null, 0, []));
        $historyStore = $this->createMock(\Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface::class);
        $historyStore->method('getHistory')->willReturn(new \Gohany\Circuitbreaker\Store\CircuitHistory([], []));

        $policy = new ProbePolicyStub(
            new PolicyDecision(true, 'half_open_probe', null, [], true),
            new \Gohany\Circuitbreaker\Store\ProbeGateConfig(1, true),
            new TransitionPlan(null, null, [], [])
        );

        $probeGate = new FakeProbeGate();
        $probeGate->allowAcquire = false;

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            new ClassifierStub(true),
            [],
            null,
            null,
            $probeGate,
            null,
            null,
            $logger
        );

        $this->expectException(ProbeGateBlockedException::class);
        $breaker->execute(new CircuitKey('test_svc'), new CircuitContext(null), function() { return 'ok'; });

        $this->assertTrue($logger->hasLog('warning', 'Probe gate blocked: too many in-flight probes'));
    }
}

class TestLogger extends AbstractLogger
{
    public array $logs = [];

    public function log($level, $message, array $context = []): void
    {
        $this->logs[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context
        ];
    }

    public function hasLog(string $level, string $messagePart): bool
    {
        foreach ($this->logs as $log) {
            if ($log['level'] === $level && strpos($log['message'], $messagePart) !== false) {
                return true;
            }
        }
        return false;
    }
}

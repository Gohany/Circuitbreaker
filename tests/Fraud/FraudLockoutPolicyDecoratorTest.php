<?php

declare(strict_types=1);

namespace tests\Policy\Fraud;

use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\CircuitPolicyInterface;
use Gohany\Circuitbreaker\Policy\Fraud\FraudLockoutConfig;
use Gohany\Circuitbreaker\Policy\Fraud\FraudLockoutPolicyDecorator;
use Gohany\Circuitbreaker\Policy\PolicyDecision;
use Gohany\Circuitbreaker\Policy\TransitionPlan;
use Gohany\Circuitbreaker\Store\CircuitHistory;
use Gohany\Circuitbreaker\Store\CircuitSnapshot;
use Gohany\Circuitbreaker\Store\CircuitState;
use PHPUnit\Framework\TestCase;

final class FraudLockoutPolicyDecoratorTest extends TestCase
{
    public function testDecideDeniesDuringFraudLockout(): void
    {
        $inner = $this->allowingPolicy();

        $cfg = new FraudLockoutConfig([
            'lockoutMs' => 3600000,
            'fraudSignals' => ['finix_fraud'],
            'metaUntilKey' => 'fraud_until_ms',
        ]);

        $policy = new FraudLockoutPolicyDecorator($inner, $cfg);

        $snap = new CircuitSnapshot(
            new CircuitState(CircuitStateMode::CLOSED, null, 0, ['fraud_until_ms' => 2000]),
            new CircuitHistory([], []),
            1000
        );

        $d = $policy->decide(new CircuitKey('svc', []), new CircuitContext(null, [], []), $snap);

        $this->assertFalse($d->allowed);
        $this->assertSame(1000, $d->retryAfterMs);
    }

    public function testOnOutcomeFraudSignalForcesOpenAndAddsSideEffect(): void
    {
        $inner = $this->allowingPolicy();

        $cfg = new FraudLockoutConfig([
            'lockoutMs' => 60000,
            'fraudSignals' => ['finix_fraud'],
        ]);

        $policy = new FraudLockoutPolicyDecorator($inner, $cfg);

        $snap = new CircuitSnapshot(
            new CircuitState(CircuitStateMode::CLOSED, null, 0, []),
            new CircuitHistory([], []),
            1000
        );

        $outcome = new CircuitOutcome(false, ['finix_fraud'], null, [], 10);

        $plan = $policy->onOutcome(new CircuitKey('svc', ['tenant' => 1]), new CircuitContext('22542', [], []), $outcome, $snap);

        $this->assertNotNull($plan->newState);
        $this->assertSame(CircuitStateMode::OPEN, $plan->newState->mode);
        $this->assertSame(61000, $plan->newState->openUntilMs);
        $this->assertArrayHasKey('fraud_until_ms', $plan->newState->meta);

        $this->assertNotEmpty($plan->sideEffects);
        $found = false;
        foreach ($plan->sideEffects as $se) {
            if ($se->domain === 'payments' && $se->type === 'fraud_lockout') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    private function allowingPolicy(): CircuitPolicyInterface
    {
        return new class implements CircuitPolicyInterface {
            public function name() { return 'inner'; }

            public function decide(CircuitKey $key, CircuitContext $context, CircuitSnapshot $snapshot): PolicyDecision
            {
                return new PolicyDecision(true, 'ok', null, [], false);
            }

            public function onOutcome(CircuitKey $key, CircuitContext $context, CircuitOutcome $outcome, CircuitSnapshot $snapshot): TransitionPlan
            {
                return new TransitionPlan(null, null, [], []);
            }
        };
    }
}

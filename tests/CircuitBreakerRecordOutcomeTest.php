<?php

declare(strict_types=1);

namespace tests;

use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\CircuitPolicyInterface;
use Gohany\Circuitbreaker\Policy\Fraud\FraudLockoutConfig;
use Gohany\Circuitbreaker\Policy\Fraud\FraudLockoutPolicyDecorator;
use Gohany\Circuitbreaker\Policy\PolicyDecision;
use Gohany\Circuitbreaker\Policy\TransitionPlan;
use Gohany\Circuitbreaker\Store\CircuitHistory;
use Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\CircuitStateStoreInterface;
use PHPUnit\Framework\TestCase;
use tests\TestDoubles\ClassifierStub;
use tests\TestDoubles\FakePsrClock;

final class CircuitBreakerRecordOutcomeTest extends TestCase
{
    public function testRecordOutcomeCanTripTenantFraudCircuitWithoutASecondExecute(): void
    {
        $clock = new FakePsrClock(1000);

        $stateStore = new class implements CircuitStateStoreInterface {
            /** @var array<string,CircuitState> */
            public array $states = [];

            public function getState(CircuitKey $key): CircuitState
            {
                $k = $this->k($key);
                if (!isset($this->states[$k])) {
                    $this->states[$k] = new CircuitState(CircuitStateMode::CLOSED, null, 0, ['version' => 0]);
                }
                return $this->states[$k];
            }

            public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool
            {
                $k = $this->k($key);
                $current = $this->getState($key);
                if ($current->mode !== $expected->mode || $current->openUntilMs !== $expected->openUntilMs) {
                    return false;
                }
                $this->states[$k] = $new;
                return true;
            }

            private function k(CircuitKey $key): string
            {
                return $key->name . '|' . json_encode($key->dimensions);
            }
        };

        $historyStore = new class implements CircuitHistoryStoreInterface {
            public function getHistory(CircuitKey $key): CircuitHistory { return new CircuitHistory([], []); }
            public function record(CircuitKey $key, \Gohany\Circuitbreaker\Store\HistoryRecord $record): void {}
        };

        $inner = $this->allowingPolicy();

        $fraudPolicy = new FraudLockoutPolicyDecorator($inner, new FraudLockoutConfig([
            'lockoutMs' => 60_000,
            'fraudSignals' => ['fraud_suspected'],
        ]));

        $reliabilityPolicy = $inner;

        $fraudBreaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $fraudPolicy,
            new ClassifierStub(true),
            [],
            null,
            $clock
        );

        $reliabilityBreaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $reliabilityPolicy,
            new ClassifierStub(true),
            [],
            null,
            $clock
        );

        $tenantFraudKey = new CircuitKey('payments_fraud', ['tenant' => 't1']);
        $providerReliabilityKey = new CircuitKey('payments_http', ['provider' => 'acme']);
        $ctx = new CircuitContext('t1', [], []);

        $this->assertSame(CircuitStateMode::CLOSED, $stateStore->getState($tenantFraudKey)->mode);
        $this->assertSame(CircuitStateMode::CLOSED, $stateStore->getState($providerReliabilityKey)->mode);

        $fraudBreaker->recordOutcome($tenantFraudKey, $ctx, new CircuitOutcome(true, ['fraud_suspected'], null, [], 0));

        $this->assertSame(CircuitStateMode::OPEN, $stateStore->getState($tenantFraudKey)->mode);
        $this->assertSame(61_000, $stateStore->getState($tenantFraudKey)->openUntilMs);

        // Provider reliability circuit is unaffected by the tenant-scoped fraud lockout.
        $this->assertSame(CircuitStateMode::CLOSED, $stateStore->getState($providerReliabilityKey)->mode);

        // And the reliability breaker can still decide/execute independently.
        $decision = $reliabilityBreaker->decide($providerReliabilityKey, $ctx);
        $this->assertTrue($decision->allowed);
    }

    private function allowingPolicy(): CircuitPolicyInterface
    {
        return new class implements CircuitPolicyInterface {
            public function name() { return 'inner'; }

            public function decide(CircuitKey $key, CircuitContext $context, \Gohany\Circuitbreaker\Store\CircuitSnapshot $snapshot): PolicyDecision
            {
                return new PolicyDecision(true, 'ok', null, [], false);
            }

            public function onOutcome(CircuitKey $key, CircuitContext $context, CircuitOutcome $outcome, \Gohany\Circuitbreaker\Store\CircuitSnapshot $snapshot): TransitionPlan
            {
                return new TransitionPlan(null, null, [], []);
            }
        };
    }
}

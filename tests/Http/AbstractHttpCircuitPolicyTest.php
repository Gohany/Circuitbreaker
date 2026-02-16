<?php

declare(strict_types=1);

namespace tests\Policy\Http;

use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Integration\Rtry\RetrySpec;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;
use Gohany\Circuitbreaker\Policy\Http\HttpCircuitPolicyConfig;
use Gohany\Circuitbreaker\Store\CircuitHistory;
use Gohany\Circuitbreaker\Store\CircuitSnapshot;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Rtry\Impl\RtryPolicy;
use PHPUnit\Framework\TestCase;

final class AbstractHttpCircuitPolicyTest extends TestCase
{
    public function testDecideOpenUsesRetrySpecForRetryAfterMs(): void
    {
        $cfg = new HttpCircuitPolicyConfig();
        $cfg->openDurationMs = 30000;

        $policy = new class($cfg) extends DefaultHttpCircuitPolicy {
            public function getRetrySpec(CircuitKey $key, CircuitContext $context): ?RetrySpec
            {
                $rtry = new RtryPolicy();
                $rtry->setStartAfterMs(60000);
                return new RetrySpec($rtry);
            }
        };

        $snap = new CircuitSnapshot(
            new CircuitState(CircuitStateMode::OPEN, 5000, 0, []),
            new CircuitHistory([], []),
            1000
        );

        $d = $policy->decide(new CircuitKey('svc', []), new CircuitContext(null, [], []), $snap);

        // Normally 5000 - 1000 = 4000
        // But RetrySpec says 60000
        $this->assertSame(60000, $d->retryAfterMs);
    }
    public function testDecideClosedAllows(): void
    {
        $policy = new DefaultHttpCircuitPolicy(new HttpCircuitPolicyConfig());

        $snap = new CircuitSnapshot(
            new CircuitState(CircuitStateMode::CLOSED, null, 0, []),
            new CircuitHistory([], []),
            1000
        );

        $d = $policy->decide(new CircuitKey('svc', []), new CircuitContext(null, [], []), $snap);

        $this->assertTrue($d->allowed);
        $this->assertFalse($d->requiresProbeGate);
    }

    public function testDecideOpenNotExpiredDenies(): void
    {
        $policy = new DefaultHttpCircuitPolicy(new HttpCircuitPolicyConfig());

        $snap = new CircuitSnapshot(
            new CircuitState(CircuitStateMode::OPEN, 5000, 0, []),
            new CircuitHistory([], []),
            1000
        );

        $d = $policy->decide(new CircuitKey('svc', []), new CircuitContext(null, [], []), $snap);

        $this->assertFalse($d->allowed);
        $this->assertSame(4000, $d->retryAfterMs);
    }

    public function testDecideOpenExpiredAllowsButRequiresProbeGate(): void
    {
        $policy = new DefaultHttpCircuitPolicy(new HttpCircuitPolicyConfig());

        $snap = new CircuitSnapshot(
            new CircuitState(CircuitStateMode::OPEN, 5000, 0, []),
            new CircuitHistory([], []),
            7000
        );

        $d = $policy->decide(new CircuitKey('svc', []), new CircuitContext(null, [], []), $snap);

        $this->assertTrue($d->allowed);
        $this->assertTrue($d->requiresProbeGate);
    }

    public function testOnOutcomeClosedFailureOpensAtThreshold(): void
    {
        $cfg = new HttpCircuitPolicyConfig([
            'consecutiveFailuresToOpen' => 5,
            'openDurationMs' => 30000,
            'openMinDurationMs' => 5000,
        ]);

        $policy = new DefaultHttpCircuitPolicy($cfg);

        $snap = new CircuitSnapshot(
            new CircuitState(CircuitStateMode::CLOSED, null, 0, []),
            new CircuitHistory(['consecutive_failures' => 4], []),
            1000
        );

        $outcome = new CircuitOutcome(false, ['timeout'], null, [], 10);

        $plan = $policy->onOutcome(new CircuitKey('svc', []), new CircuitContext(null, [], []), $outcome, $snap);

        $this->assertNotNull($plan->newState);
        $this->assertSame(CircuitStateMode::OPEN, $plan->newState->mode);
        $this->assertSame(31000, $plan->newState->openUntilMs);
        $this->assertNotEmpty($plan->sideEffects);
    }

    public function testOnOutcomeHalfOpenSuccessClosesAfterSuccessThreshold(): void
    {
        $cfg = new HttpCircuitPolicyConfig([
            'halfOpenSuccessesToClose' => 2,
        ]);

        $policy = new DefaultHttpCircuitPolicy($cfg);

        $snap = new CircuitSnapshot(
            new CircuitState(CircuitStateMode::HALF_OPEN, null, 0, ['half_open_successes' => 1]),
            new CircuitHistory([], []),
            1000
        );

        $outcome = new CircuitOutcome(true, [], null, [], 10);

        $plan = $policy->onOutcome(new CircuitKey('svc', []), new CircuitContext(null, [], []), $outcome, $snap);

        $this->assertNotNull($plan->newState);
        $this->assertSame(CircuitStateMode::CLOSED, $plan->newState->mode);
        $this->assertNotEmpty($plan->sideEffects);
    }

    public function testOnOutcomeHalfOpenFailureReOpens(): void
    {
        $cfg = new HttpCircuitPolicyConfig([
            'openDurationMs' => 30000,
        ]);

        $policy = new DefaultHttpCircuitPolicy($cfg);

        $snap = new CircuitSnapshot(
            new CircuitState(CircuitStateMode::HALF_OPEN, null, 0, ['half_open_successes' => 1]),
            new CircuitHistory([], []),
            1000
        );

        $outcome = new CircuitOutcome(false, ['http_5xx'], null, [], 10);

        $plan = $policy->onOutcome(new CircuitKey('svc', []), new CircuitContext(null, [], []), $outcome, $snap);

        $this->assertNotNull($plan->newState);
        $this->assertSame(CircuitStateMode::OPEN, $plan->newState->mode);
        $this->assertSame(31000, $plan->newState->openUntilMs);
    }

    public function testHalfOpenFailureDoesNotOpenUntilThreshold(): void
    {
        $cfg = new class extends HttpCircuitPolicyConfig {
            public function __construct()
            {
                $this->halfOpenFailuresToOpen = 2;
                $this->openDurationMs = 30000;
            }
        };


        $policy = new DefaultHttpCircuitPolicy($cfg);

        $snap = new CircuitSnapshot(
            new CircuitState(CircuitStateMode::HALF_OPEN, null, 0, ['half_open_failures' => 0]),
            new CircuitHistory([], []),
            1000
        );

        // first failure -> stay half-open, count failure
        $plan1 = $policy->onOutcome(new CircuitKey('svc', []), new CircuitContext(null, [], []),
            new CircuitOutcome(false, ['http_5xx'], null, [], 10), $snap);

        $this->assertNotNull($plan1->newState);
        $this->assertSame(CircuitStateMode::HALF_OPEN, $plan1->newState->mode);
        $this->assertSame(1, (int) $plan1->newState->meta['half_open_failures']);

        // second failure -> open
        $snap2 = new CircuitSnapshot($plan1->newState, new CircuitHistory([], []), 2000);

        $plan2 = $policy->onOutcome(new CircuitKey('svc', []), new CircuitContext(null, [], []),
            new CircuitOutcome(false, ['http_5xx'], null, [], 10), $snap2);

        $this->assertNotNull($plan2->newState);
        $this->assertSame(CircuitStateMode::OPEN, $plan2->newState->mode);
    }

}

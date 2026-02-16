<?php

namespace Gohany\Circuitbreaker\Policy\Fraud;

use Gohany\Circuitbreaker\Consts\CircuitDecisionReason;
use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\CircuitPolicyInterface;
use Gohany\Circuitbreaker\Policy\PolicyDecision;
use Gohany\Circuitbreaker\Policy\ProbeGateConfigProviderInterface;
use Gohany\Circuitbreaker\Policy\TransitionPlan;
use Gohany\Circuitbreaker\SideEffect\SideEffectRequest;
use Gohany\Circuitbreaker\Store\CircuitSnapshot;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;

final class FraudLockoutPolicyDecorator implements CircuitPolicyInterface, ProbeGateConfigProviderInterface
{
    private CircuitPolicyInterface $inner;
    private FraudLockoutConfig $cfg;

    public function __construct(CircuitPolicyInterface $inner, ?FraudLockoutConfig $cfg = null)
    {
        $this->inner = $inner;
        $this->cfg = $cfg ?: new FraudLockoutConfig();
    }

    public function name()
    {
        return $this->inner->name() . '+fraud_lockout';
    }

    public function decide(CircuitKey $key, CircuitContext $context, CircuitSnapshot $snapshot): PolicyDecision
    {
        $until = $this->getUntil($snapshot->state->meta);
        if ($until !== null && $snapshot->nowMs < $until) {
            return new PolicyDecision(false, CircuitDecisionReason::FRAUD_LOCKOUT, $until - $snapshot->nowMs, [
                $this->cfg->metaUntilKey => $until,
            ]);
        }

        return $this->inner->decide($key, $context, $snapshot);
    }

    public function probeGateConfig(CircuitKey $key, CircuitContext $context, CircuitSnapshot $snapshot, PolicyDecision $decision): ProbeGateConfig
    {
        if ($this->inner instanceof ProbeGateConfigProviderInterface) {
            return $this->inner->probeGateConfig($key, $context, $snapshot, $decision);
        }

        return new ProbeGateConfig(1, true);
    }

    public function onOutcome(CircuitKey $key, CircuitContext $context, CircuitOutcome $outcome, CircuitSnapshot $snapshot): TransitionPlan
    {
        $plan = $this->inner->onOutcome($key, $context, $outcome, $snapshot);

        if (!$this->hasAnySignal($outcome->signals, $this->cfg->fraudSignals)) {
            return $plan;
        }

        $until = $snapshot->nowMs + max(0, (int) $this->cfg->lockoutMs);

        $baseMeta = $plan->newState !== null ? $plan->newState->meta : $snapshot->state->meta;

        $fraudState = new CircuitState(
            CircuitStateMode::OPEN,
            $until,
            0,
            $this->merge($baseMeta, [$this->cfg->metaUntilKey => $until, 'fraud_reason' => 'signal'])
        );

        // If inner already opened longer, keep the longer open-until.
        if ($plan->newState !== null && $plan->newState->mode === CircuitStateMode::OPEN && $plan->newState->openUntilMs !== null) {
            if ($plan->newState->openUntilMs > $until) {
                $fraudState = new CircuitState(
                    CircuitStateMode::OPEN,
                    $plan->newState->openUntilMs,
                    0,
                    $this->merge($fraudState->meta, ['open_until_ms' => $plan->newState->openUntilMs])
                );
            }
        }

        $sideEffects = $plan->sideEffects;
        $sideEffects[] = new SideEffectRequest('payments', 'fraud_lockout', [
            'circuit' => $key->name,
            'dimensions' => $key->dimensions,
            'tenant_id' => $context->tenantId,
            'fraud_until_ms' => $until,
            'signals' => $outcome->signals,
        ]);

        return new TransitionPlan(
            $fraudState,
            $plan->historyRecord,
            $sideEffects,
            $this->merge($plan->attributes, ['transition' => 'fraud_lockout'])
        );
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function getUntil(array $meta): ?int
    {
        if (!array_key_exists($this->cfg->metaUntilKey, $meta)) {
            return null;
        }
        $v = $meta[$this->cfg->metaUntilKey];
        if ($v === null || $v === '') {
            return null;
        }
        return (int) $v;
    }

    /**
     * @param string[] $signals
     * @param string[] $needles
     */
    private function hasAnySignal(array $signals, array $needles): bool
    {
        if (empty($signals) || empty($needles)) {
            return false;
        }

        $set = array_fill_keys($signals, true);
        foreach ($needles as $n) {
            if (isset($set[$n])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private function merge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = $v;
        }
        return $a;
    }
}

<?php

namespace Gohany\Circuitbreaker\Policy\Http;

use Gohany\Circuitbreaker\Consts\CircuitDecisionReason;
use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Integration\Rtry\RetrySpec;
use Gohany\Circuitbreaker\Integration\Rtry\RetrySpecProviderInterface;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\CircuitPolicyInterface;
use Gohany\Circuitbreaker\Policy\PolicyDecision;
use Gohany\Circuitbreaker\Policy\ProbeGateConfigProviderInterface;
use Gohany\Circuitbreaker\Policy\TransitionPlan;
use Gohany\Circuitbreaker\SideEffect\SideEffectRequest;
use Gohany\Circuitbreaker\Store\CircuitSnapshot;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\HistoryRecord;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use Gohany\Rtry\Impl\RtryPolicy;

abstract class AbstractHttpCircuitPolicy implements CircuitPolicyInterface, ProbeGateConfigProviderInterface, RetrySpecProviderInterface
{
    protected HttpCircuitPolicyConfig $cfg;

    public function __construct(?HttpCircuitPolicyConfig $cfg = null)
    {
        $this->cfg = $cfg ?: new HttpCircuitPolicyConfig();
    }

    public function name()
    {
        return 'http_base';
    }

    public function decide(CircuitKey $key, CircuitContext $context, CircuitSnapshot $snapshot): PolicyDecision
    {
        if ($snapshot->state->mode === CircuitStateMode::OPEN) {
            $ou = $snapshot->state->openUntilMs;
            if ($ou !== null && $snapshot->nowMs < $ou) {
                $retryAfter = $ou - $snapshot->nowMs;
                $spec = $this->getRetrySpec($key, $context);
                if ($spec !== null) {
                    $retryAfter = max($retryAfter, $spec->getPolicy()->startAfterMs());
                }

                return new PolicyDecision(false, CircuitDecisionReason::OPEN, $retryAfter, [
                    'open_until_ms' => $ou,
                ]);
            }

            // OPEN expired -> allow, but require probe gating (half-open probe)
            return new PolicyDecision(true, CircuitDecisionReason::OPEN_EXPIRED_PROBE, null, [
                'open_until_ms' => $ou,
            ], true);
        }

        if ($snapshot->state->mode === CircuitStateMode::HALF_OPEN) {
            // Allow only if probe gate grants slot
            return new PolicyDecision(true, CircuitDecisionReason::HALF_OPEN_PROBE, null, [], true);
        }

        // CLOSED
        return new PolicyDecision(true, CircuitDecisionReason::OK, null, []);
    }

    public function getRetrySpec(CircuitKey $key, CircuitContext $context): ?RetrySpec
    {
        // Subclasses may provide an rtry policy.
        return null;
    }

    public function probeGateConfig(CircuitKey $key, CircuitContext $context, CircuitSnapshot $snapshot, PolicyDecision $decision): ProbeGateConfig
    {
        return new ProbeGateConfig($this->cfg->halfOpenMaxInFlight, true);
    }

    public function onOutcome(CircuitKey $key, CircuitContext $context, CircuitOutcome $outcome, CircuitSnapshot $snapshot): TransitionPlan
    {
        $signals = $this->normalizeSignals($outcome->signals);

        // Always record history
        $record = new HistoryRecord(
            $snapshot->nowMs,
            (bool) $outcome->success,
            $signals,
            (int) $outcome->durationMs,
            $outcome->details
        );

        // Rate limit: optionally do side-effect only (don't open)
        if ($this->cfg->denyOnRateLimit && $this->hasAnySignal($signals, $this->cfg->rateLimitSignals)) {
            return new TransitionPlan(null, $record, $this->sideEffectsOnRateLimit($key, $context, $outcome), [
                'transition' => 'rate_limit_observed',
            ]);
        }

        $isFailure = !$outcome->success && $this->isFailureSignalSet($signals);

        if ($snapshot->state->mode === CircuitStateMode::CLOSED) {
            if ($isFailure) {
                $cf = (int) ($snapshot->history->counters[$this->cfg->counterConsecutiveFailures] ?? 0);
                $nextCf = $cf + 1;

                if ($nextCf >= $this->cfg->consecutiveFailuresToOpen) {
                    $openFor = max($this->cfg->openMinDurationMs, $this->cfg->openDurationMs);
                    $until = $snapshot->nowMs + $openFor;

                    $newState = $this->withMeta(
                        new CircuitState(CircuitStateMode::OPEN, $until, 0, $snapshot->state->meta),
                        ['opened_by' => 'consecutive_failures', 'open_until_ms' => $until]
                    );

                    return new TransitionPlan($newState, $record, $this->sideEffectsOnOpen($key, $context, $outcome, $until), [
                        'transition' => 'closed_to_open',
                        'next_consecutive_failures' => $nextCf,
                    ]);
                }
            }

            return new TransitionPlan(null, $record, [], ['transition' => 'stay_closed']);
        }

        if ($snapshot->state->mode === CircuitStateMode::OPEN || $snapshot->state->mode === CircuitStateMode::HALF_OPEN) {
            return $this->handleHalfOpenOutcome($key, $context, $outcome, $snapshot, $record);
        }

        return new TransitionPlan(null, $record, [], ['transition' => 'no_state_change']);
    }

    protected function handleHalfOpenOutcome(
        CircuitKey $key,
        CircuitContext $context,
        CircuitOutcome $outcome,
        CircuitSnapshot $snapshot,
        HistoryRecord $record
    ): TransitionPlan {
        $signals = $this->normalizeSignals($outcome->signals);
        $isFailure = !$outcome->success && $this->isFailureSignalSet($signals);

        $meta = $snapshot->state->meta;
        $succ = $this->getIntMeta($meta, 'half_open_successes', 0);

        if ($isFailure) {
            $fail = $this->getIntMeta($meta, 'half_open_failures', 0);
            $fail++;

            if ($fail >= $this->cfg->halfOpenFailuresToOpen) {
                $openFor = max($this->cfg->openMinDurationMs, $this->cfg->openDurationMs);
                $until = $snapshot->nowMs + $openFor;

                $newState = $this->withMeta(
                    new CircuitState(CircuitStateMode::OPEN, $until, 0, $meta),
                    [
                        'half_open_successes' => 0,
                        'half_open_failures' => 0,
                        'open_until_ms' => $until,
                        'opened_by' => 'half_open_failure',
                    ]
                );

                return new TransitionPlan($newState, $record, $this->sideEffectsOnOpen($key, $context, $outcome, $until), [
                    'transition' => 'half_open_to_open',
                    'half_open_failures' => $fail,
                ]);
            }

            // Stay half-open but track failures
            $newState = $this->withMeta(
                new CircuitState(CircuitStateMode::HALF_OPEN, null, 0, $meta),
                ['half_open_failures' => $fail]
            );

            return new TransitionPlan($newState, $record, [], [
                'transition' => 'stay_half_open_failure',
                'half_open_failures' => $fail,
            ]);
        }

        $succ++;

        if ($succ >= $this->cfg->halfOpenSuccessesToClose) {
            $newState = $this->withMeta(
                new CircuitState(CircuitStateMode::CLOSED, null, 0, $meta),
                ['half_open_successes' => 0, 'closed_by' => 'half_open_successes']
            );

            return new TransitionPlan($newState, $record, $this->sideEffectsOnClose($key, $context, $outcome), [
                'transition' => 'half_open_to_closed',
                'half_open_successes' => $succ,
            ]);
        }

        $newState = $this->withMeta(
            new CircuitState(CircuitStateMode::CLOSED, null, 0, $meta),
            ['half_open_successes' => 0, 'half_open_failures' => 0, 'closed_by' => 'half_open_successes']
        );

        return new TransitionPlan($newState, $record, [], [
            'transition' => 'stay_half_open',
            'half_open_successes' => $succ,
        ]);
    }

    /**
     * @param string[] $signals
     */
    protected function isFailureSignalSet(array $signals): bool
    {
        return $this->hasAnySignal($signals, $this->cfg->failureSignals);
    }

    /**
     * @return SideEffectRequest[]
     */
    protected function sideEffectsOnOpen(CircuitKey $key, CircuitContext $context, CircuitOutcome $outcome, int $untilMs): array
    {
        return [
            new SideEffectRequest('circuitbreaker', 'opened', [
                'circuit' => $key->name,
                'dimensions' => $key->dimensions,
                'tenant_id' => $context->tenantId,
                'open_until_ms' => $untilMs,
                'signals' => $outcome->signals,
            ]),
        ];
    }

    /**
     * @return SideEffectRequest[]
     */
    protected function sideEffectsOnClose(CircuitKey $key, CircuitContext $context, CircuitOutcome $outcome): array
    {
        return [
            new SideEffectRequest('circuitbreaker', 'closed', [
                'circuit' => $key->name,
                'dimensions' => $key->dimensions,
                'tenant_id' => $context->tenantId,
            ]),
        ];
    }

    /**
     * @return SideEffectRequest[]
     */
    protected function sideEffectsOnRateLimit(CircuitKey $key, CircuitContext $context, CircuitOutcome $outcome): array
    {
        return [
            new SideEffectRequest('circuitbreaker', 'rate_limited', [
                'circuit' => $key->name,
                'dimensions' => $key->dimensions,
                'tenant_id' => $context->tenantId,
                'signals' => $outcome->signals,
            ]),
        ];
    }

    /**
     * @param string[] $signals
     * @param string[] $needles
     */
    protected function hasAnySignal(array $signals, array $needles): bool
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
     * @param string[] $signals
     * @return string[]
     */
    protected function normalizeSignals(array $signals): array
    {
        $out = [];
        foreach ($signals as $s) {
            $s = trim((string) $s);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param array<string,mixed> $meta
     */
    protected function getIntMeta(array $meta, string $key, int $default): int
    {
        if (!array_key_exists($key, $meta)) {
            return $default;
        }
        $v = $meta[$key];
        if ($v === null || $v === '') {
            return $default;
        }
        return (int) $v;
    }

    /**
     * @param array<string,mixed> $patch
     */
    protected function withMeta(CircuitState $state, array $patch): CircuitState
    {
        $meta = $state->meta;
        foreach ($patch as $k => $v) {
            $meta[$k] = $v;
        }

        return new CircuitState($state->mode, $state->openUntilMs, $state->halfOpenInFlight, $meta);
    }
}

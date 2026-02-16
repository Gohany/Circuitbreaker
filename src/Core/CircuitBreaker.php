<?php

namespace Gohany\Circuitbreaker\Core;

use Gohany\Circuitbreaker\Exception\CircuitDeniedException;
use Gohany\Circuitbreaker\Exception\ProbeGateBlockedException;
use Gohany\Circuitbreaker\Integration\Rtry\RetryExecutorInterface;
use Gohany\Circuitbreaker\Integration\Rtry\RetrySpecProviderInterface;
use Gohany\Circuitbreaker\Override\OverrideDeciderInterface;
use Gohany\Circuitbreaker\Policy\CircuitPolicyInterface;
use Gohany\Circuitbreaker\Policy\OutcomeClassifierInterface;
use Gohany\Circuitbreaker\Policy\PolicyDecision;
use Gohany\Circuitbreaker\Policy\ProbeGateConfigProviderInterface;
use Gohany\Circuitbreaker\Policy\TransitionPlan;
use Gohany\Circuitbreaker\SideEffect\SideEffectDispatcherInterface;
use Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface;
use Gohany\Circuitbreaker\Store\CircuitSnapshot;
use Gohany\Circuitbreaker\Store\CircuitStateStoreInterface;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use Gohany\Circuitbreaker\Store\ProbeGateInterface;
use Gohany\Circuitbreaker\Util\Time;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class CircuitBreaker implements CircuitBreakerInterface
{
    private CircuitStateStoreInterface $stateStore;
    private CircuitHistoryStoreInterface $historyStore;
    private CircuitPolicyInterface $policy;
    private OutcomeClassifierInterface $classifier;
    /** @var OverrideDeciderInterface[] */
    private array $overrideDeciders;
    private SideEffectDispatcherInterface $sideEffects;
    private ClockInterface $clock;

    private ProbeGateInterface $probeGate;

    private ?RetryExecutorInterface $retryExecutor;
    /** @var mixed */
    private $retryPolicyOrSpec;

    private LoggerInterface $logger;

    /**
     * @param OverrideDeciderInterface[] $overrideDeciders
     * @param mixed|null $retryPolicyOrSpec
     */
    public function __construct(
        CircuitStateStoreInterface $stateStore,
        CircuitHistoryStoreInterface $historyStore,
        CircuitPolicyInterface $policy,
        OutcomeClassifierInterface $classifier,
        array $overrideDeciders = [],
        ?SideEffectDispatcherInterface $sideEffects = null,
        ?ClockInterface $clock = null,
        ?ProbeGateInterface $probeGate = null,
        ?RetryExecutorInterface $retryExecutor = null,
        $retryPolicyOrSpec = null,
        ?LoggerInterface $logger = null
    ) {
        $this->stateStore = $stateStore;
        $this->historyStore = $historyStore;
        $this->policy = $policy;
        $this->classifier = $classifier;
        $this->overrideDeciders = $overrideDeciders;
        $this->sideEffects = $sideEffects ?: new \Gohany\Circuitbreaker\SideEffect\NullSideEffectDispatcher();
        $this->clock = $clock ?: new \Gohany\Circuitbreaker\Util\NativeClock();
        $this->probeGate = $probeGate ?: new \Gohany\Circuitbreaker\Store\InMemoryProbeGate();
        $this->retryExecutor = $retryExecutor;
        $this->retryPolicyOrSpec = $retryPolicyOrSpec;
        $this->logger = $logger ?: new NullLogger();
    }

    public function decide(CircuitKey $key, CircuitContext $context): CircuitDecision
    {
        $res = $this->decideWithSnapshot($key, $context);
        return $res['decision'];
    }

    public function execute(CircuitKey $key, CircuitContext $context, callable $operation)
    {
        $res = $this->decideWithSnapshot($key, $context);
        $decision = $res['decision'];
        $snapshotAtDecision = $res['snapshot'];

        if (!$decision->allowed) {
            $this->logger->warning('Circuit denied access: {reason}', [
                'circuit' => $key->name,
                'dimensions' => $key->dimensions,
                'reason' => $decision->reason,
                'retry_after_ms' => $decision->retryAfterMs,
            ]);
            throw new CircuitDeniedException($decision->reason, $decision->retryAfterMs);
        }

        $nowMs = Time::toUnixMs($this->clock->now());

        $probeAcquired = false;
        if ($decision->requiresProbeGate) {
            $probeConfig = $this->probeConfigFor($key, $context, $snapshotAtDecision, $decision);
            $gate = $this->probeGate->acquire($key, $probeConfig, $nowMs);

            if (!$gate->acquired) {
                $retryAfter = $gate->retryAfterMs > 0 ? $gate->retryAfterMs : ($decision->retryAfterMs ?? 0);
                $this->logger->warning('Probe gate blocked: too many in-flight probes', [
                    'circuit' => $key->name,
                    'dimensions' => $key->dimensions,
                    'retry_after_ms' => $retryAfter,
                ]);
                throw new ProbeGateBlockedException((int) $retryAfter);
            }

            $probeAcquired = true;
        }

        $snapshotBefore = $this->loadSnapshot($key);

        $startMs = $nowMs;
        $result = null;
        $error = null;

        $retryPolicyOrSpec = $this->retryPolicyOrSpec;
        if ($this->policy instanceof RetrySpecProviderInterface) {
            $provided = $this->policy->getRetrySpec($key, $context);
            if ($provided !== null) {
                $retryPolicyOrSpec = $provided;
            }
        }

        try {
            if ($this->retryExecutor !== null && $retryPolicyOrSpec !== null) {
                $result = $this->retryExecutor->try($this, $key, $context, $operation, $retryPolicyOrSpec);
            } else {
                $result = $operation();
            }
        } catch (\Throwable $t) {
            $error = $t;
        } finally {
            if ($probeAcquired) {
                $this->probeGate->release($key);
            }
        }

        $endMs = Time::toUnixMs($this->clock->now());
        $durationMs = max(0, $endMs - $startMs);

        $classifierAttributes = [
            'duration_ms' => $durationMs,
            'circuit' => $key->name,
            'dimensions' => $key->dimensions,
        ];
        if (isset($context->attributes['retry_attempts'])) {
            $classifierAttributes['retry_attempts'] = $context->attributes['retry_attempts'];
        }

        $outcome = $this->classifier->classify($result, $error, $classifierAttributes);
        $outcome->durationMs = (int) $durationMs;

        if ($error !== null && !$outcome->success) {
            $this->logger->error('Circuit operation failed: {error}', [
                'circuit' => $key->name,
                'dimensions' => $key->dimensions,
                'error' => $error->getMessage(),
                'duration_ms' => $durationMs,
                'outcome_signals' => $outcome->signals,
            ]);
        }

        if (($classifierAttributes['retry_attempts'] ?? 1) > 1 && !in_array('weaker_success', $outcome->signals, true)) {
            $signals = $outcome->signals;
            $signals[] = 'weaker_success';
            $outcome = new CircuitOutcome(
                $outcome->success,
                $signals,
                $outcome->error,
                $outcome->details,
                $outcome->durationMs
            );
        }

        $plan = $this->policy->onOutcome($key, $context, $outcome, $snapshotBefore);
        $this->applyPlan($key, $plan);

        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    /**
     * @return array{decision:CircuitDecision, snapshot:CircuitSnapshot}
     */
    private function decideWithSnapshot(CircuitKey $key, CircuitContext $context): array
    {
        foreach ($this->overrideDeciders as $decider) {
            $override = $decider->decide($key, $context);
            if ($override !== null) {
                return [
                    'decision' => new CircuitDecision($override->allowed, $override->reason, $override->retryAfterMs, $override->details, false),
                    'snapshot' => $this->loadSnapshot($key),
                ];
            }
        }

        $snapshot = $this->loadSnapshot($key);
        $pd = $this->policy->decide($key, $context, $snapshot);

        return [
            'decision' => new CircuitDecision($pd->allowed, $pd->reason, $pd->retryAfterMs, $pd->details, $pd->requiresProbeGate),
            'snapshot' => $snapshot,
        ];
    }

    private function probeConfigFor(CircuitKey $key, CircuitContext $context, CircuitSnapshot $snapshot, CircuitDecision $decision): ProbeGateConfig
    {
        if ($this->policy instanceof ProbeGateConfigProviderInterface) {
            $pd = new PolicyDecision(
                $decision->allowed,
                $decision->reason,
                $decision->retryAfterMs,
                $decision->details,
                $decision->requiresProbeGate
            );

            return $this->policy->probeGateConfig($key, $context, $snapshot, $pd);
        }

        return new ProbeGateConfig(1, true);
    }

    private function loadSnapshot(CircuitKey $key): CircuitSnapshot
    {
        $state = $this->stateStore->getState($key);
        $history = $this->historyStore->getHistory($key);
        $nowMs = Time::toUnixMs($this->clock->now());

        return new CircuitSnapshot($state, $history, $nowMs);
    }

    private function applyPlan(CircuitKey $key, TransitionPlan $plan): void
    {
        if ($plan->historyRecord !== null) {
            $this->historyStore->record($key, $plan->historyRecord);
        }

        if ($plan->newState !== null) {
            $current = $this->stateStore->getState($key);
            $this->stateStore->casUpdateState($key, $current, $plan->newState);

            $level = 'info';
            if ($plan->newState->mode === CircuitStateMode::OPEN) {
                $level = 'emergency';
            }

            $this->logger->log($level, 'Circuit state transition: {mode}', [
                'circuit' => $key->name,
                'mode' => $plan->newState->mode,
                'open_until_ms' => $plan->newState->openUntilMs,
                'transition_meta' => $plan->meta,
            ]);
        }

        foreach ($plan->sideEffects as $request) {
            $this->sideEffects->dispatch($request);
        }
    }
}

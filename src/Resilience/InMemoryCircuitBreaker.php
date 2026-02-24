<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

use Gohany\Circuitbreaker\Contracts\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Exception\CircuitOpenException;
use Gohany\Circuitbreaker\Observability\EmitterInterface;
use Gohany\Circuitbreaker\Observability\NullEmitter;

final class InMemoryCircuitBreaker implements CircuitBreakerInterface
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    /** @var string */
    private $id;
    /** @var CircuitBreakerConfig */
    private $cfg;
    /** @var EmitterInterface */
    private $emitter;

    /**
     * @var array<string,array{state:string,openedAt:float|null,windowTotal:int,windowFailures:int,halfOpenFailures:int,halfOpenInFlight:int,halfOpenStartedAt:float|null}>
     */
    private $ops = [];

    public function __construct(string $id, CircuitBreakerConfig $cfg, ?EmitterInterface $emitter = null)
    {
        $this->id = $id;
        $this->cfg = $cfg;
        $this->emitter = $emitter ?: new NullEmitter();
    }

    public function acquirePermission(string $operation): void
    {
        $s = &$this->state($operation);

        $now = microtime(true);

        if ($s['state'] === self::STATE_OPEN) {
            $openedAt = $s['openedAt'] ?: $now;
            if (($now - $openedAt) >= $this->cfg->openDurationSeconds) {
                $this->transition($operation, self::STATE_HALF_OPEN);
                $s['halfOpenStartedAt'] = $now;
            } else {
                $this->emitter->emit('circuit.reject', [
                    'circuit_id' => $this->id,
                    'operation' => $operation,
                    'state' => $s['state'],
                ]);
                throw new CircuitOpenException($this->id, $operation);
            }
        }

        if ($s['state'] === self::STATE_HALF_OPEN) {
            $allowed = $this->halfOpenAllowedConcurrent($s);
            if ($s['halfOpenInFlight'] >= $allowed) {
                $this->emitter->emit('circuit.reject', [
                    'circuit_id' => $this->id,
                    'operation' => $operation,
                    'state' => $s['state'],
                    'half_open_allowed' => $allowed,
                    'half_open_in_flight' => $s['halfOpenInFlight'],
                ]);
                throw new CircuitOpenException($this->id, $operation, 'Circuit half-open capacity exceeded');
            }
            $s['halfOpenInFlight']++;
        }

        $this->emitter->emit('circuit.permit', [
            'circuit_id' => $this->id,
            'operation' => $operation,
            'state' => $s['state'],
        ]);
    }

    public function recordSuccess(string $operation, ?float $durationSeconds = null): void
    {
        $s = &$this->state($operation);

        if ($s['state'] === self::STATE_HALF_OPEN) {
            if ($s['halfOpenInFlight'] > 0) {
                $s['halfOpenInFlight']--;
            }
            // Close gradually: only close when we're at full ramp and no half-open failures
            if ($this->halfOpenRampFraction($s) >= 1.0 && $s['halfOpenFailures'] === 0) {
                $this->transition($operation, self::STATE_CLOSED);
                $s['windowTotal'] = 0;
                $s['windowFailures'] = 0;
            }
        }

        $this->windowAdd($s, false);
        $this->emitter->emit('circuit.success', [
            'circuit_id' => $this->id,
            'operation' => $operation,
            'state' => $s['state'],
            'duration_s' => $durationSeconds,
        ]);
    }

    public function recordFailure(string $operation, \Throwable $error, ?float $durationSeconds = null): void
    {
        $s = &$this->state($operation);

        if ($s['state'] === self::STATE_HALF_OPEN) {
            if ($s['halfOpenInFlight'] > 0) {
                $s['halfOpenInFlight']--;
            }
            $s['halfOpenFailures']++;
            if ($s['halfOpenFailures'] >= $this->cfg->halfOpenFailuresToOpen) {
                $this->transition($operation, self::STATE_OPEN);
                $s['openedAt'] = microtime(true);
            }
        }

        $this->windowAdd($s, true);

        if ($s['state'] === self::STATE_CLOSED) {
            if ($s['windowTotal'] >= $this->cfg->minimumCalls) {
                $rate = $s['windowFailures'] / max(1, $s['windowTotal']);
                if ($rate >= $this->cfg->failureRateToOpen) {
                    $this->transition($operation, self::STATE_OPEN);
                    $s['openedAt'] = microtime(true);
                }
            }
        }

        $this->emitter->emit('circuit.failure', [
            'circuit_id' => $this->id,
            'operation' => $operation,
            'state' => $s['state'],
            'error_class' => get_class($error),
            'duration_s' => $durationSeconds,
        ]);
    }

    public function getState(string $operation): string
    {
        return $this->state($operation)['state'];
    }

    /**
     * @return array{state:string,openedAt:float|null,windowTotal:int,windowFailures:int,halfOpenFailures:int,halfOpenInFlight:int,halfOpenStartedAt:float|null}
     */
    private function &state(string $operation): array
    {
        if (!isset($this->ops[$operation])) {
            $this->ops[$operation] = [
                'state' => self::STATE_CLOSED,
                'openedAt' => null,
                'windowTotal' => 0,
                'windowFailures' => 0,
                'halfOpenFailures' => 0,
                'halfOpenInFlight' => 0,
                'halfOpenStartedAt' => null,
            ];
        }
        return $this->ops[$operation];
    }

    private function windowAdd(array &$s, bool $failed): void
    {
        $s['windowTotal']++;
        if ($failed) {
            $s['windowFailures']++;
        }
        // crude bounded window
        if ($s['windowTotal'] > 200) {
            $s['windowTotal'] = (int) floor($s['windowTotal'] / 2);
            $s['windowFailures'] = (int) floor($s['windowFailures'] / 2);
        }
    }

    private function transition(string $operation, string $to): void
    {
        $s = &$this->state($operation);
        $from = $s['state'];
        if ($from === $to) {
            return;
        }

        $s['state'] = $to;
        if ($to === self::STATE_OPEN) {
            $s['openedAt'] = microtime(true);
            $s['halfOpenFailures'] = 0;
            $s['halfOpenInFlight'] = 0;
            $s['halfOpenStartedAt'] = null;
        }
        if ($to === self::STATE_HALF_OPEN) {
            $s['halfOpenFailures'] = 0;
            $s['halfOpenInFlight'] = 0;
            $s['halfOpenStartedAt'] = microtime(true);
        }

        $this->emitter->emit('circuit.transition', [
            'circuit_id' => $this->id,
            'operation' => $operation,
            'from' => $from,
            'to' => $to,
        ]);
    }

    private function halfOpenRampFraction(array $s): float
    {
        $started = $s['halfOpenStartedAt'];
        if ($started === null) {
            return $this->cfg->halfOpenStartFraction;
        }
        $elapsed = microtime(true) - $started;
        if ($this->cfg->halfOpenRampDurationSeconds <= 0.0) {
            return 1.0;
        }
        $t = min(1.0, max(0.0, $elapsed / $this->cfg->halfOpenRampDurationSeconds));
        return $this->cfg->halfOpenStartFraction + (1.0 - $this->cfg->halfOpenStartFraction) * $t;
    }

    private function halfOpenAllowedConcurrent(array $s): int
    {
        $fraction = $this->halfOpenRampFraction($s);
        $allowed = (int) floor($this->cfg->halfOpenMaxConcurrent * $fraction);
        return max(1, $allowed);
    }
}

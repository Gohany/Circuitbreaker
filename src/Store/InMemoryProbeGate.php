<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Store;

use Gohany\Circuitbreaker\Core\CircuitKey;

final class InMemoryProbeGate implements ProbeGateInterface
{
    private array $inFlight = [];

    public function acquire(CircuitKey $key, ProbeGateConfig $config, int $nowMs): ProbeGateResult
    {
        $k = (string) $key;
        $current = $this->inFlight[$k] ?? 0;

        if ($current >= $config->maxInFlight) {
            return new ProbeGateResult(false, 'half_open', $current, 1000); 
        }

        $this->inFlight[$k] = $current + 1;
        return new ProbeGateResult(true, 'half_open', $this->inFlight[$k]);
    }

    public function release(CircuitKey $key): void
    {
        $k = (string) $key;
        if (isset($this->inFlight[$k]) && $this->inFlight[$k] > 0) {
            $this->inFlight[$k]--;
        }
    }
}

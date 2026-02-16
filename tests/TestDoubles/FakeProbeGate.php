<?php

declare(strict_types=1);

namespace tests\TestDoubles;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use Gohany\Circuitbreaker\Store\ProbeGateInterface;
use Gohany\Circuitbreaker\Store\ProbeGateResult;

final class FakeProbeGate implements ProbeGateInterface
{
    public int $acquireCalls = 0;
    public int $releaseCalls = 0;

    /** @var ProbeGateConfig[] */
    public array $configs = [];

    public bool $allowAcquire = true;
    public int $retryAfterMs = 0;

    public function acquire(CircuitKey $key, ProbeGateConfig $config, int $nowMs): ProbeGateResult
    {
        $this->acquireCalls++;
        $this->configs[] = $config;

        return new ProbeGateResult($this->allowAcquire, 'half_open', $this->allowAcquire ? 1 : 0, $this->retryAfterMs);
    }

    public function release(CircuitKey $key): void
    {
        $this->releaseCalls++;
    }
}

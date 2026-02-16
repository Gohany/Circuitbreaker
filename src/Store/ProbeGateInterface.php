<?php

namespace Gohany\Circuitbreaker\Store;

use Gohany\Circuitbreaker\Core\CircuitKey;

interface ProbeGateInterface
{
    public function acquire(CircuitKey $key, ProbeGateConfig $config, int $nowMs): ProbeGateResult;

    public function release(CircuitKey $key): void;
}

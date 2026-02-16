<?php

namespace Gohany\Circuitbreaker\Policy;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\CircuitSnapshot;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;

interface ProbeGateConfigProviderInterface
{
    public function probeGateConfig(CircuitKey $key, CircuitContext $context, CircuitSnapshot $snapshot, PolicyDecision $decision): ProbeGateConfig;
}

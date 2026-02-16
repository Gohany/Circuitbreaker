<?php

namespace Gohany\Circuitbreaker\Override;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;

interface OverrideDeciderInterface
{
    public function decide(CircuitKey $key, CircuitContext $context): ?OverrideDecision;
}

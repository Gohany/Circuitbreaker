<?php

namespace Gohany\Circuitbreaker\Policy;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\CircuitSnapshot;

interface CircuitPolicyInterface
{
    public function name();

    public function decide(CircuitKey $key, CircuitContext $context, CircuitSnapshot $snapshot): PolicyDecision;

    public function onOutcome(CircuitKey $key, CircuitContext $context, CircuitOutcome $outcome, CircuitSnapshot $snapshot): TransitionPlan;
}

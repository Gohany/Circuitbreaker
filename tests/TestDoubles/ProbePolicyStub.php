<?php

declare(strict_types=1);

namespace tests\TestDoubles;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\CircuitPolicyInterface;
use Gohany\Circuitbreaker\Policy\PolicyDecision;
use Gohany\Circuitbreaker\Policy\ProbeGateConfigProviderInterface;
use Gohany\Circuitbreaker\Policy\TransitionPlan;
use Gohany\Circuitbreaker\Store\CircuitSnapshot;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;

class ProbePolicyStub implements CircuitPolicyInterface, ProbeGateConfigProviderInterface
{
    public PolicyDecision $decision;
    public ProbeGateConfig $config;
    public TransitionPlan $plan;

    public function __construct(PolicyDecision $decision, ProbeGateConfig $config, TransitionPlan $plan)
    {
        $this->decision = $decision;
        $this->config = $config;
        $this->plan = $plan;
    }

    public function name()
    {
        return 'probe_policy_stub';
    }

    public function decide(CircuitKey $key, CircuitContext $context, CircuitSnapshot $snapshot): PolicyDecision
    {
        return $this->decision;
    }

    public function onOutcome(CircuitKey $key, CircuitContext $context, CircuitOutcome $outcome, CircuitSnapshot $snapshot): TransitionPlan
    {
        return $this->plan;
    }

    public function probeGateConfig(CircuitKey $key, CircuitContext $context, CircuitSnapshot $snapshot, PolicyDecision $decision): ProbeGateConfig
    {
        return $this->config;
    }
}

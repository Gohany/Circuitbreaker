<?php

namespace Gohany\Circuitbreaker\Core;

interface CircuitBreakerInterface
{
    public function decide(CircuitKey $key, CircuitContext $context): CircuitDecision;

    /**
     * @return mixed
     */
    public function execute(CircuitKey $key, CircuitContext $context, callable $operation);

    /**
     * Record an externally-classified outcome for the given circuit.
     *
     * This is useful when the circuit decision (e.g. network reliability) is keyed differently
     * than a secondary concern (e.g. tenant-scoped fraud lockout) and you want to update both
     * circuits without running a second dummy operation.
     */
    public function recordOutcome(CircuitKey $key, CircuitContext $context, \Gohany\Circuitbreaker\Policy\CircuitOutcome $outcome): void;
}

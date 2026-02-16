<?php

namespace Gohany\Circuitbreaker\Core;

interface CircuitBreakerInterface
{
    public function decide(CircuitKey $key, CircuitContext $context): CircuitDecision;

    /**
     * @return mixed
     */
    public function execute(CircuitKey $key, CircuitContext $context, callable $operation);
}

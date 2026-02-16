<?php

namespace Gohany\Circuitbreaker\Policy;

interface OutcomeClassifierInterface
{
    /**
     * @param mixed $result
     * @param mixed $error
     * @param array<string,mixed> $context
     */
    public function classify($result, $error, array $context = []): CircuitOutcome;
}

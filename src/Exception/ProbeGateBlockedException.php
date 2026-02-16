<?php

namespace Gohany\Circuitbreaker\Exception;

class ProbeGateBlockedException extends CircuitBreakerException
{
    private $retryAfterMs;

    public function __construct(int $retryAfterMs)
    {
        parent::__construct('Circuit denied: probe gate blocked (retryAfterMs=' . $retryAfterMs . ')');
        $this->retryAfterMs = $retryAfterMs;
    }

    public function getRetryAfterMs(): int
    {
        return $this->retryAfterMs;
    }
}

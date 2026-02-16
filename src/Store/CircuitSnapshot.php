<?php

namespace Gohany\Circuitbreaker\Store;

final class CircuitSnapshot
{
    public CircuitState $state;
    public CircuitHistory $history;
    public int $nowMs;

    public function __construct(CircuitState $state, CircuitHistory $history, int $nowMs)
    {
        $this->state = $state;
        $this->history = $history;
        $this->nowMs = $nowMs;
    }
}

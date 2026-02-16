<?php

namespace Gohany\Circuitbreaker\Store;

final class ProbeGateConfig
{
    public int $maxInFlight;
    public bool $allowOpenExpiredToHalfOpen;

    public function __construct(int $maxInFlight = 1, bool $allowOpenExpiredToHalfOpen = true)
    {
        $this->maxInFlight = $maxInFlight;
        $this->allowOpenExpiredToHalfOpen = $allowOpenExpiredToHalfOpen;
    }
}

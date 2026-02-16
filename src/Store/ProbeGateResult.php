<?php

namespace Gohany\Circuitbreaker\Store;

final class ProbeGateResult
{
    public bool $acquired;
    public string $mode;
    public int $inFlight;
    public int $retryAfterMs;

    public function __construct(bool $acquired, string $mode, int $inFlight = 0, int $retryAfterMs = 0)
    {
        $this->acquired = $acquired;
        $this->mode = $mode;
        $this->inFlight = $inFlight;
        $this->retryAfterMs = $retryAfterMs;
    }
}

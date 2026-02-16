<?php

namespace Gohany\Circuitbreaker\Core;

final class CircuitDecision
{
    public bool $allowed;
    public string $reason;
    public ?int $retryAfterMs;
    /** @var array<string,mixed> */
    public array $details;
    public bool $requiresProbeGate;

    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        bool $allowed,
        string $reason,
        ?int $retryAfterMs = null,
        array $details = [],
        bool $requiresProbeGate = false
    ) {
        $this->allowed = $allowed;
        $this->reason = $reason;
        $this->retryAfterMs = $retryAfterMs;
        $this->details = $details;
        $this->requiresProbeGate = $requiresProbeGate;
    }
}

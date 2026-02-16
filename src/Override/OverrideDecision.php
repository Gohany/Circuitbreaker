<?php

namespace Gohany\Circuitbreaker\Override;

final class OverrideDecision
{
    public bool $allowed;
    public string $reason;
    public ?int $retryAfterMs;
    /** @var array<string,mixed> */
    public array $details;

    /**
     * @param array<string,mixed> $details
     */
    public function __construct(bool $allowed, string $reason, ?int $retryAfterMs = null, array $details = [])
    {
        $this->allowed = $allowed;
        $this->reason = $reason;
        $this->retryAfterMs = $retryAfterMs;
        $this->details = $details;
    }
}

<?php

namespace Gohany\Circuitbreaker\Exception;

class CircuitDeniedException extends CircuitBreakerException
{
    private $reason;
    private $retryAfterMs;

    public function __construct(string $reason, ?int $retryAfterMs = null)
    {
        $message = 'Circuit denied: ' . $reason;
        if ($retryAfterMs !== null) {
            $message .= ' (retryAfterMs=' . $retryAfterMs . ')';
        }
        parent::__construct($message);
        $this->reason = $reason;
        $this->retryAfterMs = $retryAfterMs;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getRetryAfterMs(): ?int
    {
        return $this->retryAfterMs;
    }
}

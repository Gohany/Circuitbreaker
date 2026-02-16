<?php

namespace Gohany\Circuitbreaker\Policy;

final class CircuitOutcome
{
    public bool $success;
    /** @var string[] */
    public array $signals;
    /** @var mixed|null */
    public $error;
    /** @var array<string,mixed> */
    public array $details;
    public int $durationMs;

    /**
     * @param string[] $signals
     * @param array<string,mixed> $details
     */
    public function __construct(bool $success, array $signals = [], $error = null, array $details = [], int $durationMs = 0)
    {
        $this->success = $success;
        $this->signals = $signals;
        $this->error = $error;
        $this->details = $details;
        $this->durationMs = $durationMs;
    }
}

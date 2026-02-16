<?php

namespace Gohany\Circuitbreaker\Store;

final class CircuitState
{
    public string $mode;
    public ?int $openUntilMs;
    public int $halfOpenInFlight;
    /** @var array<string,mixed> */
    public array $meta;

    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(string $mode, ?int $openUntilMs, int $halfOpenInFlight = 0, array $meta = [])
    {
        $this->mode = $mode;
        $this->openUntilMs = $openUntilMs;
        $this->halfOpenInFlight = $halfOpenInFlight;
        $this->meta = $meta;
    }
}

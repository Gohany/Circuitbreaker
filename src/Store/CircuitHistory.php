<?php

namespace Gohany\Circuitbreaker\Store;

final class CircuitHistory
{
    /** @var array<string,mixed> */
    public array $counters;
    /** @var array<int,mixed> */
    public array $window;

    /**
     * @param array<string,mixed> $counters
     * @param array<int,mixed> $window
     */
    public function __construct(array $counters = [], array $window = [])
    {
        $this->counters = $counters;
        $this->window = $window;
    }
}

<?php

namespace Gohany\Circuitbreaker\Core;

final class CircuitKey
{
    public string $name;
    /** @var array<string,mixed> */
    public array $dimensions;

    /**
     * @param array<string,mixed> $dimensions
     */
    public function __construct(string $name, array $dimensions = [])
    {
        $this->name = $name;
        $this->dimensions = $dimensions;
    }
}

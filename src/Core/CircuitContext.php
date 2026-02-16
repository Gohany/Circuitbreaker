<?php

namespace Gohany\Circuitbreaker\Core;

final class CircuitContext
{
    public ?string $tenantId;
    /** @var array<string,mixed> */
    public array $attributes;
    /** @var array<string,mixed> */
    public array $meta;

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $meta
     */
    public function __construct(?string $tenantId, array $attributes = [], array $meta = [])
    {
        $this->tenantId = $tenantId;
        $this->attributes = $attributes;
        $this->meta = $meta;
    }
}

<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

final class Context
{
    /** @var string */
    private $operation;
    /** @var string */
    private $lane;

    /** @var array<string,mixed> */
    private $attributes = [];

    public function __construct(string $operation, string $lane)
    {
        $this->operation = $operation;
        $this->lane = $lane;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getLane(): string
    {
        return $this->lane;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->attributes;
    }
}

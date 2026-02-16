<?php

namespace Gohany\Circuitbreaker\SideEffect;

final class SideEffectRequest
{
    public string $domain;
    public string $type;
    /** @var array<string,mixed> */
    public array $payload;
    /** @var array<string,mixed> */
    public array $meta;

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $meta
     */
    public function __construct(string $domain, string $type, array $payload = [], array $meta = [])
    {
        $this->domain = $domain;
        $this->type = $type;
        $this->payload = $payload;
        $this->meta = $meta;
    }
}

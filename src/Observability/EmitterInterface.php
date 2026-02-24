<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Observability;

interface EmitterInterface
{
    /**
     * @param array<string,mixed> $context
     */
    public function emit(string $eventName, array $context = []): void;
}

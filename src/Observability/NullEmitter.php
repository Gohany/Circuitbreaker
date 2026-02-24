<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Observability;

final class NullEmitter implements EmitterInterface
{
    /**
     * @param array<string,mixed> $context
     */
    public function emit(string $eventName, array $context = []): void
    {
        // noop
    }
}

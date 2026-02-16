<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\SideEffect;

final class NullSideEffectDispatcher implements SideEffectDispatcherInterface
{
    public function dispatch(SideEffectRequest $request): void
    {
        // No-op
    }
}

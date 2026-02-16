<?php

declare(strict_types=1);

namespace tests\TestDoubles;

use Gohany\Circuitbreaker\SideEffect\SideEffectDispatcherInterface;
use Gohany\Circuitbreaker\SideEffect\SideEffectRequest;

final class FakeSideEffectDispatcher implements SideEffectDispatcherInterface
{
    /** @var SideEffectRequest[] */
    public array $dispatched = [];

    public function dispatch(SideEffectRequest $request)
    {
        $this->dispatched[] = $request;
    }
}

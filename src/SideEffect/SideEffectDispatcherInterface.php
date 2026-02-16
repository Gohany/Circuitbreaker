<?php

namespace Gohany\Circuitbreaker\SideEffect;

interface SideEffectDispatcherInterface
{
    public function dispatch(SideEffectRequest $request);
}

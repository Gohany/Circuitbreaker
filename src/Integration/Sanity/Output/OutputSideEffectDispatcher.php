<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Sanity\Output;

use Gohany\Circuitbreaker\SideEffect\SideEffectDispatcherInterface;
use Gohany\Circuitbreaker\SideEffect\SideEffectRequest;

final class OutputSideEffectDispatcher implements SideEffectDispatcherInterface
{
    private SanityCheckOutputInterface $output;

    private ?SideEffectDispatcherInterface $inner;

    public function __construct(SanityCheckOutputInterface $output, ?SideEffectDispatcherInterface $inner = null)
    {
        $this->output = $output;
        $this->inner = $inner;
    }

    public function dispatch(SideEffectRequest $request)
    {
        $this->output->sideEffect($request);
        if ($this->inner !== null) {
            $this->inner->dispatch($request);
        }
    }
}

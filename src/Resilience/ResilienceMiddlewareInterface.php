<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

interface ResilienceMiddlewareInterface
{
    /**
     * @template T
     * @param Context $ctx
     * @param callable(Context):T $next
     * @return T
     */
    public function handle(Context $ctx, callable $next);
}

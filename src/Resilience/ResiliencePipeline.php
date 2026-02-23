<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

final class ResiliencePipeline
{
    /** @var ResilienceMiddlewareInterface[] */
    private $middlewares;

    /**
     * @param ResilienceMiddlewareInterface[] $middlewares
     */
    public function __construct(array $middlewares)
    {
        $this->middlewares = array_values($middlewares);
    }

    /**
     * @template T
     * @param Context $ctx
     * @param callable():T $operation
     * @return T
     */
    public function execute(Context $ctx, callable $operation)
    {
        $core = function (Context $ctx) use ($operation) {
            return $operation();
        };

        $next = $core;
        for ($i = count($this->middlewares) - 1; $i >= 0; $i--) {
            $mw = $this->middlewares[$i];
            $prev = $next;
            $next = function (Context $ctx) use ($mw, $prev) {
                return $mw->handle($ctx, $prev);
            };
        }

        return $next($ctx);
    }
}

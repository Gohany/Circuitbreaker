<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

use Gohany\Circuitbreaker\Contracts\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Observability\EmitterInterface;
use Gohany\Circuitbreaker\Observability\NullEmitter;

final class CircuitBreakerMiddleware implements ResilienceMiddlewareInterface
{
    /** @var CircuitBreakerInterface */
    private $circuit;
    /** @var EmitterInterface */
    private $emitter;

    public function __construct(CircuitBreakerInterface $circuit, ?EmitterInterface $emitter = null)
    {
        $this->circuit = $circuit;
        $this->emitter = $emitter ?: new NullEmitter();
    }

    public function handle(Context $ctx, callable $next)
    {
        $op = $ctx->getOperation();
        $this->circuit->acquirePermission($op);

        $start = microtime(true);
        try {
            $res = $next($ctx);
            $this->circuit->recordSuccess($op, microtime(true) - $start);
            return $res;
        } catch (\Throwable $e) {
            $this->circuit->recordFailure($op, $e, microtime(true) - $start);
            throw $e;
        }
    }
}

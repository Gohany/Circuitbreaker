<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

use Gohany\Circuitbreaker\Contracts\BulkheadInterface;
use Gohany\Circuitbreaker\Observability\EmitterInterface;
use Gohany\Circuitbreaker\Observability\NullEmitter;

final class BulkheadMiddleware implements ResilienceMiddlewareInterface
{
    /** @var BulkheadInterface */
    private $bulkhead;
    /** @var EmitterInterface */
    private $emitter;

    public function __construct(BulkheadInterface $bulkhead, ?EmitterInterface $emitter = null)
    {
        $this->bulkhead = $bulkhead;
        $this->emitter = $emitter ?: new NullEmitter();
    }

    public function handle(Context $ctx, callable $next)
    {
        $lane = $ctx->getLane();
        $permit = $this->bulkhead->acquire($lane);
        $ctx->set('bulkhead_permit_id', $permit->getId());

        $this->emitter->emit('pipeline.bulkhead_enter', [
            'operation' => $ctx->getOperation(),
            'lane' => $lane,
            'permit_id' => $permit->getId(),
        ]);

        try {
            return $next($ctx);
        } finally {
            $permit->release();
            $this->emitter->emit('pipeline.bulkhead_exit', [
                'operation' => $ctx->getOperation(),
                'lane' => $lane,
                'permit_id' => $permit->getId(),
            ]);
        }
    }
}

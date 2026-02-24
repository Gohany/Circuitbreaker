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

    /** @var LaneRouterInterface|null */
    private $laneRouter;

    public function __construct(
        BulkheadInterface $bulkhead,
        ?EmitterInterface $emitter = null,
        ?LaneRouterInterface $laneRouter = null
    )
    {
        $this->bulkhead = $bulkhead;
        $this->emitter = $emitter ?: new NullEmitter();
        $this->laneRouter = $laneRouter;
    }

    public function handle(Context $ctx, callable $next)
    {
        $lane = $ctx->getLane();
        if ($this->laneRouter instanceof LaneRouterInterface) {
            $lane = $this->laneRouter->laneFor($ctx);
            $ctx->setLane($lane);
        }
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

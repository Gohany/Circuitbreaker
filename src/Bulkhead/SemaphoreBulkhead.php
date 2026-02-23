<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Bulkhead;

use Gohany\Circuitbreaker\Contracts\BulkheadInterface;
use Gohany\Circuitbreaker\Contracts\BulkheadPermitInterface;
use Gohany\Circuitbreaker\Exception\BulkheadRejectedException;
use Gohany\Circuitbreaker\Observability\EmitterInterface;
use Gohany\Circuitbreaker\Observability\NullEmitter;

/**
 * Process-local bulkhead (non-distributed).
 */
final class SemaphoreBulkhead implements BulkheadInterface
{
    /** @var string */
    private $id;
    /** @var int */
    private $maxConcurrent;
    /** @var int */
    private $inFlight = 0;
    /** @var EmitterInterface */
    private $emitter;

    public function __construct(string $id, int $maxConcurrent, ?EmitterInterface $emitter = null)
    {
        $this->id = $id;
        $this->maxConcurrent = $maxConcurrent;
        $this->emitter = $emitter ?: new NullEmitter();
    }

    public function acquire(string $lane, ?float $timeoutSeconds = null): BulkheadPermitInterface
    {
        if ($this->inFlight >= $this->maxConcurrent) {
            $this->emitter->emit('bulkhead.acquire_rejected', [
                'bulkhead_id' => $this->id,
                'lane' => $lane,
                'global_in_flight' => $this->inFlight,
                'global_max' => $this->maxConcurrent,
            ]);
            throw new BulkheadRejectedException($this->id, $lane);
        }

        $this->inFlight++;
        $pid = bin2hex(random_bytes(8));
        $this->emitter->emit('bulkhead.acquire_ok', [
            'bulkhead_id' => $this->id,
            'lane' => $lane,
            'permit_id' => $pid,
            'global_in_flight' => $this->inFlight,
            'global_max' => $this->maxConcurrent,
        ]);

        return new BulkheadPermit($pid, $lane, function (string $laneName, string $permitId): void {
            if ($this->inFlight > 0) {
                $this->inFlight--;
            }
            $this->emitter->emit('bulkhead.release', [
                'bulkhead_id' => $this->id,
                'lane' => $laneName,
                'permit_id' => $permitId,
                'global_in_flight' => $this->inFlight,
                'global_max' => $this->maxConcurrent,
            ]);
        });
    }

    public function run(string $lane, callable $fn, ?float $timeoutSeconds = null)
    {
        $permit = $this->acquire($lane, $timeoutSeconds);
        try {
            return $fn();
        } finally {
            $permit->release();
        }
    }
}

<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Contracts;

use Gohany\Circuitbreaker\Exception\BulkheadRejectedException;

interface BulkheadInterface
{
    /**
     * Attempt to acquire capacity for the given lane.
     *
     * Implementations MUST be safe to call in finally blocks by ensuring release()
     * is idempotent for unknown/expired permits.
     *
     * @throws BulkheadRejectedException
     */
    public function acquire(string $lane, ?float $timeoutSeconds = null): BulkheadPermitInterface;

    /**
     * Convenience API for running a callback under bulkhead control.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function run(string $lane, callable $fn, ?float $timeoutSeconds = null);
}

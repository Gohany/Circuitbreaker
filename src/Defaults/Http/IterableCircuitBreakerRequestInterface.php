<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Psr\Http\Message\RequestInterface;

/**
 * Optional interface for PSR-7 requests that want to provide an ordered list of circuits.
 *
 * The order of the yielded {@see CircuitTarget} items MUST match the order of the circuit
 * definitions passed to {@see MultiCircuitBreakingPsr18Client}.
 */
interface IterableCircuitBreakerRequestInterface extends RequestInterface, \IteratorAggregate
{
    /**
     * @return \Traversable<CircuitTarget>
     */
    public function getIterator(): \Traversable;
}

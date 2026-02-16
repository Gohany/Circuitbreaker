<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Psr\Http\Message\RequestInterface;

/**
 * Optional interface for PSR-7 requests that want to explicitly define 
 * their circuit breaking parameters.
 */
interface CircuitBreakerRequestInterface extends RequestInterface
{
    /**
     * @return CircuitKey|null
     */
    public function getCircuitKey(): ?CircuitKey;

    /**
     * @return CircuitContext|null
     */
    public function getCircuitContext(): ?CircuitContext;
}

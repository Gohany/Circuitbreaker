<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Psr\Http\Message\RequestInterface;

/**
 * Builder for clients that coordinate multiple circuits for a single HTTP request.
 */
interface MultiHttpCircuitBuilderInterface extends HttpCircuitBuilderInterface
{
    /**
     * Build the secondary circuit key (e.g. tenant fraud lockout).
     * Return null to disable the secondary circuit for this request.
     */
    public function buildSecondaryKey(RequestInterface $request, string $prefix): ?CircuitKey;

    /**
     * Build the secondary context (defaults to primary context when null).
     */
    public function buildSecondaryContext(RequestInterface $request): ?CircuitContext;
}

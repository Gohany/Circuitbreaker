<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Psr\Http\Message\RequestInterface;

/**
 * A composable piece of a circuit key.
 *
 * Pieces are combined by `CircuitBreakerKeyFactory` in a stable way.
 */
interface CircuitBreakerKeyPieceInterface
{
    /**
     * Stable identifier used for deterministic ordering.
     */
    public function id(): string;

    public function contribute(RequestInterface $request): CircuitBreakerKeyContribution;
}

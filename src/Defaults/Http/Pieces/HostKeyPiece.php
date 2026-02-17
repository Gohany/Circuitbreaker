<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http\Pieces;

use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerKeyContribution;
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerKeyPieceInterface;
use Psr\Http\Message\RequestInterface;

final class HostKeyPiece implements CircuitBreakerKeyPieceInterface
{
    public function id(): string
    {
        return 'http.host';
    }

    public function contribute(RequestInterface $request): CircuitBreakerKeyContribution
    {
        $host = trim((string) $request->getUri()->getHost());
        $host = $host !== '' ? strtolower($host) : 'unknown';

        return new CircuitBreakerKeyContribution([$host], []);
    }
}

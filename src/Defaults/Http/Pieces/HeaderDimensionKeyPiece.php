<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http\Pieces;

use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerKeyContribution;
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerKeyPieceInterface;
use Psr\Http\Message\RequestInterface;

final class HeaderDimensionKeyPiece implements CircuitBreakerKeyPieceInterface
{
    private string $id;
    private string $headerName;
    private string $dimensionName;

    public function __construct(string $id, string $headerName, string $dimensionName)
    {
        $this->id = $id;
        $this->headerName = $headerName;
        $this->dimensionName = $dimensionName;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function contribute(RequestInterface $request): CircuitBreakerKeyContribution
    {
        if (!$request->hasHeader($this->headerName)) {
            return new CircuitBreakerKeyContribution();
        }

        $v = trim($request->getHeaderLine($this->headerName));
        if ($v === '') {
            return new CircuitBreakerKeyContribution();
        }

        return new CircuitBreakerKeyContribution([], [
            $this->dimensionName => $v,
        ]);
    }
}

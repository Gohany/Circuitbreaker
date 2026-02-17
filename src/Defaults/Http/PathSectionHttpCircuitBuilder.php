<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Defaults\Http\Pieces\HostKeyPiece;
use Gohany\Circuitbreaker\Defaults\Http\Pieces\PathSectionDimensionKeyPiece;
use Psr\Http\Message\RequestInterface;

/**
 * HTTP circuit builder that scopes by host + first N path sections (as a dimension).
 *
 * This is useful when a single upstream host has multiple distinct endpoints with
 * different reliability characteristics.
 */
final class PathSectionHttpCircuitBuilder implements HttpCircuitBuilderInterface
{
    private int $sections;
    private string $dimensionName;
    private string $tenantHeaderName;

    /** @var CircuitBreakerKeyPieceInterface[] */
    private array $pieces;

    public function __construct(int $sections = 1, string $dimensionName = 'path_section', string $tenantHeaderName = 'X-Tenant-Id')
    {
        $this->sections = $sections;
        $this->dimensionName = $dimensionName;
        $this->tenantHeaderName = $tenantHeaderName;

        $this->pieces = [
            new HostKeyPiece(),
            new PathSectionDimensionKeyPiece($this->sections, $this->dimensionName),
        ];
    }

    public function buildKey(RequestInterface $request, string $prefix): CircuitKey
    {
        $factory = new CircuitBreakerKeyFactory($prefix, $this->pieces);
        return $factory->build($request);
    }

    public function buildContext(RequestInterface $request): CircuitContext
    {
        $tenantId = null;
        if ($request->hasHeader($this->tenantHeaderName)) {
            $tenantId = trim($request->getHeaderLine($this->tenantHeaderName));
        }
        if ($tenantId === '') {
            $tenantId = null;
        }

        return new CircuitContext($tenantId);
    }
}

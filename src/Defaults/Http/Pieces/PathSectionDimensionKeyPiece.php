<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http\Pieces;

use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerKeyContribution;
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerKeyPieceInterface;
use Psr\Http\Message\RequestInterface;

final class PathSectionDimensionKeyPiece implements CircuitBreakerKeyPieceInterface
{
    private int $sections;
    private string $dimensionName;

    public function __construct(int $sections = 1, string $dimensionName = 'path_section')
    {
        $this->sections = $sections;
        $this->dimensionName = $dimensionName;
    }

    public function id(): string
    {
        return 'http.path_section.' . $this->dimensionName . '.' . $this->sections;
    }

    public function contribute(RequestInterface $request): CircuitBreakerKeyContribution
    {
        $sections = max(0, $this->sections);
        $path = trim((string) $request->getUri()->getPath());

        $segments = [];
        if ($path !== '' && $path !== '/') {
            foreach (explode('/', $path) as $seg) {
                $seg = trim($seg);
                if ($seg === '') {
                    continue;
                }
                $seg = str_replace(':', '_', $seg);
                $segments[] = $seg;
            }
        }

        $parts = array_slice($segments, 0, $sections);
        $section = empty($parts) ? 'root' : implode('/', $parts);

        return new CircuitBreakerKeyContribution([], [
            $this->dimensionName => $section,
        ]);
    }
}

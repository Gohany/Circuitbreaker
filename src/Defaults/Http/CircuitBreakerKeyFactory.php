<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Psr\Http\Message\RequestInterface;

/**
 * Builds a `CircuitKey` by composing multiple `CircuitBreakerKeyPieceInterface` instances.
 *
 * The resulting key is deterministic regardless of the order pieces are passed to the factory.
 */
final class CircuitBreakerKeyFactory
{
    private string $prefix;

    /** @var CircuitBreakerKeyPieceInterface[] */
    private array $pieces;

    /**
     * @param CircuitBreakerKeyPieceInterface[] $pieces
     */
    public function __construct(string $prefix, array $pieces)
    {
        $this->prefix = $prefix;
        $this->pieces = $pieces;
    }

    public function build(RequestInterface $request): CircuitKey
    {
        $pieces = $this->pieces;
        usort($pieces, function (CircuitBreakerKeyPieceInterface $a, CircuitBreakerKeyPieceInterface $b): int {
            return strcmp($a->id(), $b->id());
        });

        $nameParts = [];
        $dimensions = [];

        foreach ($pieces as $p) {
            $c = $p->contribute($request);

            foreach ($c->nameParts as $part) {
                $part = trim((string) $part);
                if ($part === '') {
                    continue;
                }
                $nameParts[] = $part;
            }

            foreach ($c->dimensions as $k => $v) {
                $dimensions[(string) $k] = $v;
            }
        }

        $name = $this->prefix;
        if (!empty($nameParts)) {
            $name .= ':' . implode(':', $nameParts);
        }

        ksort($dimensions);

        return new CircuitKey($name, $dimensions);
    }
}

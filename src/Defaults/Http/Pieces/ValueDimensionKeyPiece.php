<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http\Pieces;

use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerKeyContribution;
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerKeyPieceInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Generic key piece that contributes a fixed dimension value.
 *
 * The provided `$id` is used both as:
 * - the deterministic ordering identifier (via `id()`), and
 * - the dimension name in the resulting `CircuitKey`.
 */
final class ValueDimensionKeyPiece implements CircuitBreakerKeyPieceInterface
{
    private string $id;

    /** @var mixed */
    private $value;

    /**
     * @param mixed $value
     */
    public function __construct(string $id, $value)
    {
        $this->id = $id;
        $this->value = $value;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function contribute(RequestInterface $request): CircuitBreakerKeyContribution
    {
        $v = $this->value;

        if ($v === null) {
            return new CircuitBreakerKeyContribution();
        }

        if (is_string($v)) {
            $v = trim($v);
            if ($v === '') {
                return new CircuitBreakerKeyContribution();
            }
        }

        return new CircuitBreakerKeyContribution([], [
            $this->id => $v,
        ]);
    }
}

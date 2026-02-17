<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

/**
 * Contribution from a single key-piece.
 */
final class CircuitBreakerKeyContribution
{
    /** @var string[] */
    public array $nameParts;

    /** @var array<string,mixed> */
    public array $dimensions;

    /**
     * @param string[] $nameParts
     * @param array<string,mixed> $dimensions
     */
    public function __construct(array $nameParts = [], array $dimensions = [])
    {
        $this->nameParts = $nameParts;
        $this->dimensions = $dimensions;
    }
}

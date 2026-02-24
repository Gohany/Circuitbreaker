<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Contracts;

use Gohany\Circuitbreaker\Exception\CircuitOpenException;

interface CircuitBreakerInterface
{
    /**
     * @throws CircuitOpenException when the circuit is open and request is not permitted.
     */
    public function acquirePermission(string $operation): void;

    public function recordSuccess(string $operation, ?float $durationSeconds = null): void;

    public function recordFailure(string $operation, \Throwable $error, ?float $durationSeconds = null): void;

    public function getState(string $operation): string;
}

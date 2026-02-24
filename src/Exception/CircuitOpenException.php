<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Exception;

use RuntimeException;

final class CircuitOpenException extends RuntimeException
{
    /** @var string */
    private $circuitId;
    /** @var string */
    private $operation;

    public function __construct(string $circuitId, string $operation, string $message = 'Circuit is open')
    {
        parent::__construct($message);
        $this->circuitId = $circuitId;
        $this->operation = $operation;
    }

    public function getCircuitId(): string
    {
        return $this->circuitId;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }
}

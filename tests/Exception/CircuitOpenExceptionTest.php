<?php

declare(strict_types=1);

namespace tests\Exception;

use Gohany\Circuitbreaker\Exception\CircuitOpenException;
use PHPUnit\Framework\TestCase;

final class CircuitOpenExceptionTest extends TestCase
{
    public function testCarriesCircuitIdAndOperation(): void
    {
        $e = new CircuitOpenException('c1', 'op1');

        $this->assertSame('Circuit is open', $e->getMessage());
        $this->assertSame('c1', $e->getCircuitId());
        $this->assertSame('op1', $e->getOperation());
    }

    public function testCustomMessage(): void
    {
        $e = new CircuitOpenException('c1', 'op1', 'half-open capacity exceeded');
        $this->assertSame('half-open capacity exceeded', $e->getMessage());
    }
}

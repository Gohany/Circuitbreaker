<?php

declare(strict_types=1);

namespace tests\Core;

use Gohany\Circuitbreaker\Core\CircuitKey;
use PHPUnit\Framework\TestCase;

final class CircuitKeyTest extends TestCase
{
    public function testIdWithoutDimensionsIsName(): void
    {
        $k = new CircuitKey('dep');
        self::assertSame('dep', $k->id());
    }

    public function testIdSortsDimensionsForStability(): void
    {
        $k1 = new CircuitKey('dep', ['b' => 2, 'a' => 1]);
        $k2 = new CircuitKey('dep', ['a' => 1, 'b' => 2]);

        self::assertSame($k1->id(), $k2->id());
        self::assertSame('dep|{"a":1,"b":2}', $k1->id());
    }
}

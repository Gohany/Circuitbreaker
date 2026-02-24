<?php

declare(strict_types=1);

namespace tests\Store\Redis;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;
use PHPUnit\Framework\TestCase;

final class RedisKeyBuilderTest extends TestCase
{
    public function testGlobalDimensionUsesLiteralGlobalInHumanReadableMode(): void
    {
        $b = new RedisKeyBuilder('cb', true);
        $k = new CircuitKey('dep');

        self::assertSame('cb:dep:global:state', $b->stateKey($k));
        self::assertSame('cb:dep:global:counters', $b->countersKey($k));
        self::assertSame('cb:dep:global:override', $b->overrideKey($k));
        self::assertSame('cb:dep:global:b:*', $b->bucketPattern($k));
        self::assertSame('cb:dep:global:b:123', $b->bucketKey($k, 123));
    }

    public function testDimensionIdNormalizesTypesAndSorts(): void
    {
        $b = new RedisKeyBuilder('cb', true);

        $k = new CircuitKey('dep', [
            'z' => null,
            'a' => true,
            'b' => false,
            'c' => ['x' => 1],
            'd' => 12,
        ]);

        // Human-readable mode exposes the normalized dimension string directly.
        $expectedDim = 'a=1|b=0|c={"x":1}|d=12|z=null';
        self::assertSame('cb:dep:' . $expectedDim . ':state', $b->stateKey($k));
    }

    public function testHashedModeUsesSha1OfNormalizedDimensionString(): void
    {
        $b = new RedisKeyBuilder('cb', false);
        $k = new CircuitKey('dep', ['b' => 2, 'a' => 1]);

        $dim = 'a=1|b=2';
        self::assertSame('cb:dep:' . sha1($dim) . ':state', $b->stateKey($k));
    }

    public function testLockKeyUsesPrefix(): void
    {
        $b = new RedisKeyBuilder('pfx', false);
        self::assertSame('pfx:lock:abc', $b->lockKey('abc'));
    }
}

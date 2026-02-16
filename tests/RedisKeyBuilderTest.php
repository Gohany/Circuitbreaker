<?php

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;

final class RedisKeyBuilderTest extends TestCase
{
    public function testBucketPatternMatchesBucketKeys(): void
    {
        $kb = new RedisKeyBuilder('cb', false);
        $key = new CircuitKey('finix:charges', ['tenant' => 22542, 'subject' => 'card:fp:abc']);

        $pattern = $kb->bucketPattern($key);
        $bucket0 = $kb->bucketKey($key, 0);
        $bucket123 = $kb->bucketKey($key, 123);

        $this->assertStringEndsWith(':b:*', $pattern);
        $this->assertStringStartsWith(substr($pattern, 0, -1), $bucket0);
        $this->assertStringStartsWith(substr($pattern, 0, -1), $bucket123);
    }

    public function testHashKeyStableAcrossDimensionOrder(): void
    {
        $kb = new RedisKeyBuilder('cb', false);

        $k1 = new CircuitKey('svc', ['b' => '2', 'a' => '1']);
        $k2 = new CircuitKey('svc', ['a' => '1', 'b' => '2']);

        $this->assertSame($kb->stateKey($k1), $kb->stateKey($k2));
        $this->assertSame($kb->bucketPattern($k1), $kb->bucketPattern($k2));
        $this->assertSame($kb->overrideKey($k1), $kb->overrideKey($k2));
    }
}

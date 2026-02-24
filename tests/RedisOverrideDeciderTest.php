<?php

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Override\Redis\RedisOverrideDecider;
use Gohany\Circuitbreaker\Override\Redis\RedisOverrideStore;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;
use tests\TestDoubles\FakePsrClock;
use tests\TestDoubles\FakeRedisClient;

final class RedisOverrideDeciderTest extends TestCase
{
    public function testForceDenyBeatsForceAllow(): void
    {
        $redis = new FakeRedisClient();
        $redis->setNowMs(10000);

        $clock = new FakePsrClock(10000);

        $kb = new RedisKeyBuilder('cb', true);
        $store = new RedisOverrideStore($redis, $kb);
        $decider = new RedisOverrideDecider($store, $clock);

        $key = new CircuitKey('svc', []);
        $store->set($key, [
            'force_allow' => '1',
            'force_deny' => '1',
            'forced_mode' => '',
            'forced_until_ms' => '',
            'reason' => 'test',
            'meta_json' => '{}',
        ], null);

        $od = $decider->decide($key, new CircuitContext(null, [], []));
        $this->assertNotNull($od);
        $this->assertFalse($od->allowed);
        $this->assertStringContainsString('force_deny', $od->reason);
    }

    public function testForcedOpenDeniesUntilExpiry(): void
    {
        $redis = new FakeRedisClient();
        $redis->setNowMs(10000);

        $clock = new FakePsrClock(10000);

        $kb = new RedisKeyBuilder('cb', true);
        $store = new RedisOverrideStore($redis, $kb);
        $decider = new RedisOverrideDecider($store, $clock);

        $key = new CircuitKey('svc', []);
        $store->set($key, [
            'force_allow' => '',
            'force_deny' => '',
            'forced_mode' => 'open',
            'forced_until_ms' => '11000',
            'reason' => 'incident',
            'meta_json' => '{}',
        ], 1000);

        $od1 = $decider->decide($key, new CircuitContext(null, [], []));
        $this->assertNotNull($od1);
        $this->assertFalse($od1->allowed);

        $redis->setNowMs(12000);
        $clock->setNowMs(12000);

        $od2 = $decider->decide($key, new CircuitContext(null, [], []));
        $this->assertNull($od2);
    }
}

<?php

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Override\Redis\RedisCircuitAdmin;
use Gohany\Circuitbreaker\Override\Redis\RedisOverrideStore;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\CircuitStateStoreInterface;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;
use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use tests\TestDoubles\FakePsrClock;
use tests\TestDoubles\FakeRedisClient;

final class RedisCircuitAdminTest extends TestCase
{
    public function testResetHistoryDeletesCountersAndBuckets(): void
    {
        $redis = new FakeRedisClient();
        $redis->setNowMs(60000);

        $clock = new FakePsrClock(60000);

        $kb = new RedisKeyBuilder('cb', true);
        $overrideStore = new RedisOverrideStore($redis, $kb);

        $stateStore = new class implements CircuitStateStoreInterface {
            public function getState(CircuitKey $key): CircuitState { return new CircuitState(CircuitStateMode::CLOSED, null, 0, ['version' => 0]); }
            public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool { return true; }
        };

        $admin = new RedisCircuitAdmin($redis, $kb, $overrideStore, $stateStore, $clock);

        $key = new CircuitKey('svc', ['tenant' => 1]);

        $redis->hMSet($kb->countersKey($key), ['total_failure' => '5', 'consecutive_failures' => '5']);
        $redis->hMSet($kb->bucketKey($key, 1), ['failure' => '2']);
        $redis->hMSet($kb->bucketKey($key, 2), ['failure' => '3']);

        $this->assertTrue($redis->exists($kb->countersKey($key)));
        $this->assertTrue($redis->exists($kb->bucketKey($key, 1)));

        $admin->resetHistory($key);

        $this->assertFalse($redis->exists($kb->countersKey($key)));
        $this->assertFalse($redis->exists($kb->bucketKey($key, 1)));
        $this->assertFalse($redis->exists($kb->bucketKey($key, 2)));
    }

    public function testForgiveHistoryDeletesBucketsSinceMinuteAndResetsConsecutiveFailures(): void
    {
        $redis = new FakeRedisClient();
        $redis->setNowMs(180000);

        $clock = new FakePsrClock(180000);

        $kb = new RedisKeyBuilder('cb', true);
        $overrideStore = new RedisOverrideStore($redis, $kb);

        $stateStore = new class implements CircuitStateStoreInterface {
            public function getState(CircuitKey $key): CircuitState { return new CircuitState(CircuitStateMode::CLOSED, null, 0, ['version' => 0]); }
            public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool { return true; }
        };

        $admin = new RedisCircuitAdmin($redis, $kb, $overrideStore, $stateStore, $clock);

        $key = new CircuitKey('svc', ['tenant' => 1]);

        $redis->hMSet($kb->bucketKey($key, 1), ['failure' => '1']);
        $redis->hMSet($kb->bucketKey($key, 2), ['failure' => '1']);
        $redis->hMSet($kb->bucketKey($key, 3), ['failure' => '1']);

        $redis->hMSet($kb->countersKey($key), ['consecutive_failures' => '7']);

        $admin->forgiveHistory($key, 150000);

        $this->assertTrue($redis->exists($kb->bucketKey($key, 1)));
        $this->assertFalse($redis->exists($kb->bucketKey($key, 2)));
        $this->assertFalse($redis->exists($kb->bucketKey($key, 3)));

        $counters = $redis->hGetAll($kb->countersKey($key));
        $this->assertSame('0', $counters['consecutive_failures']);
    }
}

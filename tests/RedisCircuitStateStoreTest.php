<?php

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\Redis\RedisCircuitStateStore;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;
use tests\TestDoubles\FakeRedisClient;

final class RedisCircuitStateStoreTest extends TestCase
{
    public function testCasUpdateStateRespectsVersionAndIncrements(): void
    {
        $redis = new FakeRedisClient();
        $redis->setNowMs(1000);

        $kb = new RedisKeyBuilder('cb', true);
        $store = new RedisCircuitStateStore($redis, $kb);

        $key = new CircuitKey('svc', ['tenant' => 1]);
        $stateKey = $kb->stateKey($key);

        $redis->hMSet($stateKey, [
            'version' => '2',
            'mode' => CircuitStateMode::CLOSED,
            'open_until_ms' => '',
            'half_open_in_flight' => '0',
            'meta_json' => '{}',
        ]);

        $expected = new CircuitState(CircuitStateMode::CLOSED, null, 0, ['version' => 2]);
        $new = new CircuitState(CircuitStateMode::OPEN, 9999, 0, ['reason' => 'test', 'version' => 2]);

        $ok = $store->casUpdateState($key, $expected, $new);
        $this->assertTrue($ok);

        $raw = $redis->hGetAll($stateKey);
        $this->assertSame('3', $raw['version']);
        $this->assertSame(CircuitStateMode::OPEN, $raw['mode']);
        $this->assertSame('9999', $raw['open_until_ms']);
    }

    public function testCasUpdateStateFailsOnWrongVersion(): void
    {
        $redis = new FakeRedisClient();
        $redis->setNowMs(1000);

        $kb = new RedisKeyBuilder('cb', true);
        $store = new RedisCircuitStateStore($redis, $kb);

        $key = new CircuitKey('svc', []);
        $stateKey = $kb->stateKey($key);

        $redis->hMSet($stateKey, [
            'version' => '5',
            'mode' => CircuitStateMode::CLOSED,
            'open_until_ms' => '',
            'half_open_in_flight' => '0',
            'meta_json' => '{}',
        ]);

        $expected = new CircuitState(CircuitStateMode::CLOSED, null, 0, ['version' => 4]);
        $new = new CircuitState(CircuitStateMode::OPEN, 2000, 0, ['version' => 4]);

        $ok = $store->casUpdateState($key, $expected, $new);
        $this->assertFalse($ok);
    }
}

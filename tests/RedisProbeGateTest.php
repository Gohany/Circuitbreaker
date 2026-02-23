<?php

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;
use Gohany\Circuitbreaker\Store\Redis\RedisProbeGate;
use tests\TestDoubles\FakeRedisClient;

final class RedisProbeGateTest extends TestCase
{
    public function testAcquireFromOpenExpiredTransitionsToHalfOpenAndIncrements(): void
    {
        $redis = new FakeRedisClient();
        $redis->setNowMs(10000);

        $kb = new RedisKeyBuilder('cb', true);
        $gate = new RedisProbeGate($redis, $kb);

        $key = new CircuitKey('svc', ['tenant' => 1]);
        $stateKey = $kb->stateKey($key);

        $redis->hMSet($stateKey, [
            'mode' => 'open',
            'open_until_ms' => '9000',
            'half_open_in_flight' => '0',
            'version' => '1',
            'meta_json' => '{}',
        ]);

        $res = $gate->acquire($key, new ProbeGateConfig(1, true), 10000);
        $this->assertTrue($res->acquired);
        $this->assertSame('half_open', $res->mode);
        $this->assertSame(1, $res->inFlight);

        $raw = $redis->hGetAll($stateKey);
        $this->assertSame('half_open', $raw['mode']);
        $this->assertSame('1', $raw['half_open_in_flight']);
    }

    public function testAcquireHalfOpenRespectsMaxInFlight(): void
    {
        $redis = new FakeRedisClient();
        $redis->setNowMs(10000);

        $kb = new RedisKeyBuilder('cb', true);
        $gate = new RedisProbeGate($redis, $kb);

        $key = new CircuitKey('svc', []);
        $stateKey = $kb->stateKey($key);

        $redis->hMSet($stateKey, [
            'mode' => 'half_open',
            'open_until_ms' => '',
            'half_open_in_flight' => '1',
        ]);

        $res = $gate->acquire($key, new ProbeGateConfig(1, true), 10000);
        $this->assertFalse($res->acquired);
        $this->assertSame(250, $res->retryAfterMs);
    }

    public function testReleaseDecrementsInFlight(): void
    {
        $redis = new FakeRedisClient();
        $redis->setNowMs(10000);

        $kb = new RedisKeyBuilder('cb', true);
        $gate = new RedisProbeGate($redis, $kb);

        $key = new CircuitKey('svc', []);
        $stateKey = $kb->stateKey($key);

        $redis->hMSet($stateKey, [
            'mode' => 'half_open',
            'half_open_in_flight' => '2',
        ]);

        $gate->release($key);

        $raw = $redis->hGetAll($stateKey);
        $this->assertSame('1', $raw['half_open_in_flight']);
    }
}

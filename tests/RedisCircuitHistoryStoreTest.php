<?php

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\HistoryRecord;
use Gohany\Circuitbreaker\Store\Redis\RedisCircuitHistoryStore;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;
use tests\TestDoubles\FakeRedisClient;

final class RedisCircuitHistoryStoreTest extends TestCase
{
    public function testRecordUpdatesCountersBucketsAndConsecutiveFailures(): void
    {
        $redis = new FakeRedisClient();
        $redis->setNowMs(60000);

        $kb = new RedisKeyBuilder('cb', true);
        $store = new RedisCircuitHistoryStore($redis, $kb, 900, 0);

        $key = new CircuitKey('svc', ['tenant' => 1]);

        $store->record($key, new HistoryRecord(60000, false, ['timeout', 'http_5xx'], 123, []));
        $store->record($key, new HistoryRecord(61000, false, ['timeout'], 50, []));
        $store->record($key, new HistoryRecord(62000, true, [], 20, []));

        $hist = $store->getHistory($key);

        $this->assertSame(2, $hist->counters['total_failure']);
        $this->assertSame(1, $hist->counters['total_success']);
        $this->assertSame(0, $hist->counters['consecutive_failures']);

        $bucketKey = $kb->bucketKey($key, 1);
        $bucket = $redis->hGetAll($bucketKey);

        $this->assertSame('1', $bucket['success']);
        $this->assertSame('2', $bucket['failure']);
        $this->assertSame('2', $bucket['timeout']);
        $this->assertSame('1', $bucket['http_5xx']);
    }
}

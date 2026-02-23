<?php

declare(strict_types=1);

namespace tests\Bulkhead;

use Gohany\Circuitbreaker\Bulkhead\LanePolicy;
use Gohany\Circuitbreaker\Bulkhead\PoolPolicy;
use Gohany\Circuitbreaker\Bulkhead\RedisFairQueueBulkhead;
use PHPUnit\Framework\TestCase;
use tests\Util\InMemoryRedisClient;

final class RedisFairQueueBulkheadTest extends TestCase
{
    public function testQueueScanSkipsBlockedLaneToAdmitAdmissibleLaterEntry(): void
    {
        $redis = new InMemoryRedisClient();

        $policy = new PoolPolicy(
            'db-main',
            2,
            PoolPolicy::MODE_FIXED,
            0.0, // always enforce lane caps
            [
                'login' => LanePolicy::fixed('login', 1),
                'payments' => LanePolicy::fixed('payments', 1),
            ]
        );

        $bh = new RedisFairQueueBulkhead($redis, $policy, 'test', null, [
            'scan_limit' => 10,
            'pump_per_call' => 5,
            'poll_interval_ms' => 1,
        ]);

        // Occupy login lane (cap=1)
        $p1 = $bh->acquire('login');

        // Manually enqueue an older login request that is now blocked by lane cap.
        $queueKey = 'test:bulkhead:pool:db-main:queue';
        $nowMs = (int) (microtime(true) * 1000);
        $redis->zadd($queueKey, (float) ($nowMs - 10), 'oldlogin|login|' . (string) ($nowMs + 500));

        // Now a payments request should still be able to acquire by skipping the blocked older login entry.
        $p2 = $bh->acquire('payments', 0.05);

        $p2->release();
        $p1->release();

        $this->assertTrue(true);
    }

    public function testWeightedPumpPrefersHigherWeightLaneUnderContention(): void
    {
        $redis = new InMemoryRedisClient();

        $policy = new PoolPolicy(
            'db-main',
            10,
            PoolPolicy::MODE_WEIGHTED,
            0.0, // always enforce lane caps (soft borrowing off)
            [
                'login' => LanePolicy::weight('login', 1),
                'payments' => LanePolicy::weight('payments', 5),
            ]
        );

        $bh = new RedisFairQueueBulkhead($redis, $policy, 'test', null, [
            'scan_limit' => 10,
            'pump_per_call' => 1,
            'poll_interval_ms' => 1,
        ]);

        $queueKey = 'test:bulkhead:pool:db-main:queue';
        $nowMs = (int) (microtime(true) * 1000);

        // login is older but low weight
        $redis->zadd($queueKey, (float) ($nowMs - 2), 'log1|login|' . (string) ($nowMs + 500));
        // payments is slightly newer but higher weight; priority = age*weight => 1*5 > 2*1
        $redis->zadd($queueKey, (float) ($nowMs - 1), 'pmt1|payments|' . (string) ($nowMs + 500));

        // Trigger a single pump with a fast-fail acquire (timeoutSeconds=null means no wait).
        try {
            $bh->acquire('login', null);
            $this->fail('Expected BulkheadRejectedException');
        } catch (\Gohany\Circuitbreaker\Exception\BulkheadRejectedException $e) {
            // ignore
        }

        // Inspect redis "strings" to confirm the pump granted the payment request first.
        $ref = new \ReflectionClass($redis);
        $prop = $ref->getProperty('strings');
        $prop->setAccessible(true);
        $strings = (array) $prop->getValue($redis);

        $expectedGrantKey = 'test:bulkhead:pool:db-main:grant:pmt1';
        $this->assertArrayHasKey($expectedGrantKey, $strings);
    }
}

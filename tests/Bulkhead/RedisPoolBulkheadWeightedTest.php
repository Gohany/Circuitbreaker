<?php

declare(strict_types=1);

namespace tests\Bulkhead;

use Gohany\Circuitbreaker\Bulkhead\LanePolicy;
use Gohany\Circuitbreaker\Bulkhead\PoolPolicy;
use Gohany\Circuitbreaker\Bulkhead\RedisPoolBulkhead;
use Gohany\Circuitbreaker\Exception\BulkheadRejectedException;
use PHPUnit\Framework\TestCase;
use Tests\Util\InMemoryRedisClient;

final class RedisPoolBulkheadWeightedTest extends TestCase
{
    public function testSoftBorrowThenEnforceLaneCaps(): void
    {
        $redis = new InMemoryRedisClient();

        $policy = new PoolPolicy(
            'db-main',
            10,
            PoolPolicy::MODE_WEIGHTED,
            0.50,
            [
                'login' => LanePolicy::weight('login', 1),
                'payments' => LanePolicy::weight('payments', 5),
                'other' => LanePolicy::weight('other', 1),
            ]
        );

        $bh = new RedisPoolBulkhead($redis, $policy, 'test');

        $permits = [];
        // First 5 are allowed due to soft borrowing
        for ($i = 0; $i < 5; $i++) {
            $permits[] = $bh->acquire('login');
        }

        $this->expectException(BulkheadRejectedException::class);
        $bh->acquire('login');

        foreach ($permits as $p) {
            $p->release();
        }
    }
}

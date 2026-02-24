<?php

declare(strict_types=1);

namespace tests\Integration;

/**
 * @group integration
 */
final class RedisFairQueueBulkheadSoakIntegrationTest extends IntegrationTestCase
{
    public function testSoakNoPermitLeaksAndStableThroughput(): void
    {
        $dsn = getenv('GOHANY_CB_TEST_REDIS_DSN') ?: 'redis://127.0.0.1:6379/15';
        $prefix = $this->prefix('it_soak_' . bin2hex(random_bytes(4)));

        $poolId = 'soak';
        $globalMax = 5;

        $procs = [];
        for ($i = 0; $i < 16; $i++) {
            $procs[] = $this->spawnWorker([
                'dsn' => $dsn,
                'prefix' => $prefix,
                'poolId' => $poolId,
                'lane' => ($i % 4 === 0) ? 'payments.charge' : 'auth.login',
                'globalMax' => $globalMax,
                'mode' => 'weighted',
                'laneWeights' => 'auth.login=1,payments.charge=6',
                'iterations' => 30,
                'holdMs' => 5,
                'timeoutMs' => 400,
                'queueScanLimit' => 64,
                'pumpPerCall' => 3,
                'grantTtlMs' => 200,
                'pollIntervalMs' => 5,
            ]);
        }

        $totalOk = 0;
        $totalRejected = 0;
        foreach ($procs as $p) {
            $out = $this->waitForWorker($p);
            $totalOk += (int) $out['ok'];
            $totalRejected += (int) $out['rejected'];
        }

        $this->assertGreaterThan(200, $totalOk, 'Expected soak test to complete a large number of successful acquires');

        $maxObserved = (int) ($this->redis()->get($prefix . ':max_in_flight') ?: 0);
        $this->assertLessThanOrEqual($globalMax, $maxObserved);

        $current = (int) ($this->redis()->get($prefix . ':current_in_flight') ?: 0);
        $this->assertSame(0, $current, 'No permit leaks after soak');

        // Queue depth should not blow up unbounded (basic guard).
        $queueDepth = (int) ($this->redis()->get($prefix . ':queue_max_depth') ?: 0);
        $this->assertLessThan(1000, $queueDepth, 'Queue depth grew unreasonably large; possible leak or stuck pump');
    }
}

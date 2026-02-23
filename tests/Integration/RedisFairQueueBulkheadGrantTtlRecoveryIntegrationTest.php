<?php

declare(strict_types=1);

namespace tests\Integration;

/**
 * @group integration
 */
final class RedisFairQueueBulkheadGrantTtlRecoveryIntegrationTest extends IntegrationTestCase
{
    public function testGrantTtlExpiresAndDoesNotLeakCapacity(): void
    {
        $dsn = getenv('GOHANY_CB_TEST_REDIS_DSN') ?: 'redis://127.0.0.1:6379/15';
        $prefix = $this->prefix('it_grantttl_' . bin2hex(random_bytes(4)));

        $poolId = 'grant-ttl';
        $globalMax = 2;

        // Worker A: enqueues and triggers pumping, but exits before consuming the grant (simulated crash).
        $procA = $this->spawnWorker([
            'dsn' => $dsn,
            'prefix' => $prefix,
            'poolId' => $poolId,
            'lane' => 'auth.login',
            'globalMax' => $globalMax,
            'mode' => 'fixed',
            'iterations' => 1,
            'holdMs' => 0,
            'timeoutMs' => 200,
            'queueScanLimit' => 64,
            'pumpPerCall' => 1,
            'grantTtlMs' => 200,
            'pollIntervalMs' => 10,
            'crashAfterGrant' => 1,
        ]);

        // Give A time to enqueue + pump and crash.
        usleep(150000);

        // Worker B: should still be able to acquire up to globalMax after the grant TTL expires.
        // If grants leak capacity, B would get stuck/rejected more than expected.
        $procB = $this->spawnWorker([
            'dsn' => $dsn,
            'prefix' => $prefix,
            'poolId' => $poolId,
            'lane' => 'payments.charge',
            'globalMax' => $globalMax,
            'mode' => 'fixed',
            'iterations' => 6,
            'holdMs' => 25,
            'timeoutMs' => 500,
            'queueScanLimit' => 64,
            'pumpPerCall' => 2,
            'grantTtlMs' => 200,
            'pollIntervalMs' => 10,
        ]);

        $outA = $this->waitForWorker($procA);
        $outB = $this->waitForWorker($procB);

        $this->assertSame('auth.login', $outA['lane']);
        $this->assertGreaterThanOrEqual(0, $outA['ok']);

        $this->assertSame('payments.charge', $outB['lane']);
        $this->assertGreaterThanOrEqual(4, $outB['ok'], 'Expected most acquires to succeed; grant TTL should prevent leaked capacity');

        $maxObserved = (int) ($this->redis()->get($prefix . ':max_in_flight') ?: 0);
        $this->assertLessThanOrEqual($globalMax, $maxObserved, 'Max observed concurrency must never exceed global max');

        // After all workers complete, current concurrency should return to 0.
        $current = (int) ($this->redis()->get($prefix . ':current_in_flight') ?: 0);
        $this->assertSame(0, $current, 'Concurrency counter should return to 0 (no leaks).');
    }
}

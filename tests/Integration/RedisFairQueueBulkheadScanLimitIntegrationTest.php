<?php

declare(strict_types=1);

namespace tests\Integration;

/**
 * @group integration
 */
final class RedisFairQueueBulkheadScanLimitIntegrationTest extends IntegrationTestCase
{
    public function testScanLimitBoundsWorkAndMayDelayAdmissibleLaterEntry(): void
    {
        $dsn = getenv('GOHANY_CB_TEST_REDIS_DSN') ?: 'redis://127.0.0.1:6379/15';
        $prefix = $this->prefix('it_scanlimit_' . bin2hex(random_bytes(4)));

        $poolId = 'scan-limit';

        // Fill login lane cap=1 so the login lane is at capacity.
        $procFill = $this->spawnWorker([
            'dsn' => $dsn,
            'prefix' => $prefix,
            'poolId' => $poolId,
            'lane' => 'auth.login',
            'globalMax' => 2,
            'mode' => 'fixed',
            'laneCaps' => 'auth.login=1,payments.charge=1',
            'iterations' => 1,
            'holdMs' => 400,
            'timeoutMs' => 100,
            'queueScanLimit' => 1, // Critical: only examine the head of queue
            'pumpPerCall' => 1,
            'grantTtlMs' => 250,
            'pollIntervalMs' => 10,
        ]);

        usleep(100000);

        // Enqueue a second login request that will be blocked by the lane cap and remain at the head
        // of the queue (oldest request), so with scan_limit=1 the pump cannot look ahead.
        $procBlockedLogin = $this->spawnWorker([
            'dsn' => $dsn,
            'prefix' => $prefix,
            'poolId' => $poolId,
            'lane' => 'auth.login',
            'globalMax' => 2,
            'mode' => 'fixed',
            'laneCaps' => 'auth.login=1,payments.charge=1',
            'iterations' => 1,
            'holdMs' => 0,
            'timeoutMs' => 800,
            'queueScanLimit' => 1,
            'pumpPerCall' => 1,
            'grantTtlMs' => 250,
            'pollIntervalMs' => 10,
        ]);

        usleep(50000);

        // Now attempt payments acquire with scan_limit=1; if the head is blocked and scan limit is 1,
        // the pump can't look ahead far enough, so payments may time out.
        $procPay = $this->spawnWorker([
            'dsn' => $dsn,
            'prefix' => $prefix,
            'poolId' => $poolId,
            'lane' => 'payments.charge',
            'globalMax' => 2,
            'mode' => 'fixed',
            'laneCaps' => 'auth.login=1,payments.charge=1',
            'iterations' => 1,
            'holdMs' => 0,
            'timeoutMs' => 200,
            'queueScanLimit' => 1,
            'pumpPerCall' => 1,
            'grantTtlMs' => 250,
            'pollIntervalMs' => 10,
        ]);

        $outPay = $this->waitForWorker($procPay);
        $this->waitForWorker($procBlockedLogin);
        $this->waitForWorker($procFill);

        $this->assertSame('payments.charge', $outPay['lane']);
        $this->assertSame(0, $outPay['ok'], 'With scan_limit=1 and a blocked head-of-queue, payment is expected to time out/reject');
        $this->assertGreaterThanOrEqual(1, $outPay['rejected']);
    }
}

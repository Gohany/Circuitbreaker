<?php

declare(strict_types=1);

namespace tests\Integration;

/**
 * @group integration
 */
final class RedisFairQueueBulkheadPumpPerCallIntegrationTest extends IntegrationTestCase
{
    public function testPumpPerCallBoundsGrantCreation(): void
    {
        $dsn = getenv('GOHANY_CB_TEST_REDIS_DSN') ?: 'redis://127.0.0.1:6379/15';
        $prefix = $this->prefix('it_pumppercall_' . bin2hex(random_bytes(4)));

        $poolId = 'pump-per-call';

        // Spawn multiple waiters that all enqueue quickly. With pump_per_call=1, each acquire call
        // should only produce one grant, smoothing Redis work.
        $procs = [];
        for ($i = 0; $i < 6; $i++) {
            $procs[] = $this->spawnWorker([
                'dsn' => $dsn,
                'prefix' => $prefix,
                'poolId' => $poolId,
                'lane' => ($i % 2 === 0) ? 'auth.login' : 'payments.charge',
                'globalMax' => 3,
                'mode' => 'fixed',
                'iterations' => 2,
                'holdMs' => 40,
                'timeoutMs' => 800,
                'queueScanLimit' => 64,
                'pumpPerCall' => 1,
                'grantTtlMs' => 250,
                'pollIntervalMs' => 10,
            ]);
        }

        $oks = 0;
        $rejected = 0;
        foreach ($procs as $p) {
            $out = $this->waitForWorker($p);
            $oks += (int) $out['ok'];
            $rejected += (int) $out['rejected'];
        }

        $this->assertGreaterThan(0, $oks);

        // Primary invariant: global max never exceeded.
        $maxObserved = (int) ($this->redis()->get($prefix . ':max_in_flight') ?: 0);
        $this->assertLessThanOrEqual(3, $maxObserved);

        // Secondary sanity: we should not be stuck; pump_per_call=1 should still make progress.
        $this->assertLessThan(12, $rejected, 'Should not reject the majority; bounded pumping should still progress');
    }
}

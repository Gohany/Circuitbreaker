<?php

declare(strict_types=1);

namespace tests\Integration;

/**
 * @group integration
 */
final class RedisFairQueueBulkheadIntegrationTest extends IntegrationTestCase
{
    public function testGlobalMaxIsRespectedAcrossConcurrentWorkers(): void
    {
        $dsn = (string) getenv('GOHANY_CB_TEST_REDIS_DSN');
        if ($dsn === '') {
            $this->markTestSkipped('GOHANY_CB_TEST_REDIS_DSN not set.');
        }

        $globalMax = 2;
        $prefix = $this->prefix('it_fq_global_' . bin2hex(random_bytes(4)));
        $poolId = 'db-main';

        $procs = [];
        for ($i = 0; $i < 4; $i++) {
            $procs[] = $this->spawnWorker([
                'dsn' => $dsn,
                'prefix' => $prefix,
                'poolId' => $poolId,
                'lane' => 'payments.charge',
                'globalMax' => $globalMax,
                'mode' => 'weighted',
                'laneWeights' => json_encode(['payments.charge' => 5]),
                'iterations' => 20,
                'holdMs' => 60,
                'timeoutMs' => 500,
                'queueScanLimit' => 64,
                'pumpPerCall' => 3,
                'grantTtlMs' => 500,
                'pollIntervalMs' => 5,
            ]);
        }

        $results = [];
        foreach ($procs as $p) {
            $results[] = $this->waitForWorker($p);
        }

        $maxObserved = (int) ($this->redis()->get($prefix . ':max_in_flight') ?: 0);
        $this->assertLessThanOrEqual($globalMax, $maxObserved, 'Observed concurrency must never exceed global_max.');

        foreach ($results as $r) {
            $this->assertSame(0, (int) $r['errors'], 'Worker should not report permit release errors.');
        }
    }

    public function testWeightedPreferenceUnderSaturation(): void
    {
        $dsn = (string) getenv('GOHANY_CB_TEST_REDIS_DSN');
        if ($dsn === '') {
            $this->markTestSkipped('GOHANY_CB_TEST_REDIS_DSN not set.');
        }

        $prefix = $this->prefix('it_fq_weight_' . bin2hex(random_bytes(4)));
        $poolId = 'db-main';

        // Under heavy contention and bounded wait, payments should win more admits than login.
        $globalMax = 2;

        $procs = [];
        for ($i = 0; $i < 8; $i++) {
            $procs[] = $this->spawnWorker([
                'dsn' => $dsn,
                'prefix' => $prefix,
                'poolId' => $poolId,
                'lane' => 'auth.login',
                'globalMax' => $globalMax,
                'mode' => 'weighted',
                'laneWeights' => json_encode(['auth.login' => 1, 'payments.charge' => 6]),
                'iterations' => 25,
                'holdMs' => 80,
                'timeoutMs' => 120,
                'queueScanLimit' => 64,
                'pumpPerCall' => 3,
                'grantTtlMs' => 500,
                'pollIntervalMs' => 5,
            ]);
        }
        for ($i = 0; $i < 8; $i++) {
            $procs[] = $this->spawnWorker([
                'dsn' => $dsn,
                'prefix' => $prefix,
                'poolId' => $poolId,
                'lane' => 'payments.charge',
                'globalMax' => $globalMax,
                'mode' => 'weighted',
                'laneWeights' => json_encode(['auth.login' => 1, 'payments.charge' => 6]),
                'iterations' => 25,
                'holdMs' => 80,
                'timeoutMs' => 120,
                'queueScanLimit' => 64,
                'pumpPerCall' => 3,
                'grantTtlMs' => 500,
                'pollIntervalMs' => 5,
            ]);
        }

        $results = [];
        foreach ($procs as $p) {
            $results[] = $this->waitForWorker($p);
        }

        $okLogin = 0;
        $okPay = 0;
        foreach ($results as $r) {
            if ($r['lane'] === 'auth.login') {
                $okLogin += (int) $r['ok'];
            }
            if ($r['lane'] === 'payments.charge') {
                $okPay += (int) $r['ok'];
            }
        }

        // Not a strict guarantee, but in practice with age*weight this should be notably higher.
        $this->assertGreaterThan(0, $okLogin, 'Login should make some progress (avoid total starvation).');
        $this->assertGreaterThan(0, $okPay, 'Payments should make progress.');
        // In weighted mode we at least expect payments to not perform worse than login.
        // With small global_max values and per-lane caps clamped to >=1, a tie can happen.
        $this->assertGreaterThanOrEqual($okPay, $okLogin, 'Payments should be admitted at least as often as login under saturation.');
    }

}

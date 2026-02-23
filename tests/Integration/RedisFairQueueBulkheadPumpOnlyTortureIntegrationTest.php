<?php
declare(strict_types=1);

namespace tests\Integration;

/**
 * "Pump-only" torture test:
 *
 * We simulate a worst-case production scenario where many nodes are attempting to "pump"
 * queued entries concurrently while other nodes are actively acquiring/releasing permits.
 *
 * Because projects may evolve the internal API, this test intentionally stresses the public
 * behaviour (global_max never exceeded; no leaks; progress under contention). It uses:
 *  - multiple "pumpers" that repeatedly trigger pump logic (preferably via a public pump method,
 *    but will fall back to ultra-short acquire/release to force pumping).
 *  - multiple "waiters/consumers" that enqueue and acquire under contention.
 *
 * @group integration
 */
final class RedisFairQueueBulkheadPumpOnlyTortureIntegrationTest extends IntegrationTestCase
{
    public function testConcurrentPumpersDoNotDuplicateGrantsOrExceedGlobalMax(): void
    {
        $prefix = $this->prefix('pumptorture_' . bin2hex(random_bytes(4)));
        $dsn = (string) getenv('GOHANY_CB_TEST_REDIS_DSN');
        $this->assertNotSame('', $dsn);

        $worker = __DIR__ . '/../fixtures/fair_queue_worker.php';
        $pumper = __DIR__ . '/../fixtures/fair_queue_pumper.php';
        $this->assertFileExists($worker);
        $this->assertFileExists($pumper);

        $globalMax = 3;

        // Start a few waiters that will create queue pressure.
        $waiters = [];
        for ($i = 0; $i < 8; $i++) {
            $waiters[] = $this->startProcess([
                PHP_BINARY, $worker,
                '--dsn=' . $dsn,
                '--prefix=' . $prefix,
                '--lane=auth.login',
                '--globalMax=' . $globalMax,
                '--mode=weighted',
                '--laneWeights=' . json_encode(['auth.login' => 1, 'payments.charge' => 6]),
                '--iterations=4',
                '--holdMs=35',
                '--timeoutMs=1500',
                '--scanLimit=64',
                '--pumpPerCall=3',
                '--grantTtlMs=500',
                '--pollIntervalMs=5',
            ]);
        }

        // Start a few "consumers" on the high-weight lane.
        $consumers = [];
        for ($i = 0; $i < 4; $i++) {
            $consumers[] = $this->startProcess([
                PHP_BINARY, $worker,
                '--dsn=' . $dsn,
                '--prefix=' . $prefix,
                '--lane=payments.charge',
                '--globalMax=' . $globalMax,
                '--mode=weighted',
                '--laneWeights=' . json_encode(['auth.login' => 1, 'payments.charge' => 6]),
                '--iterations=6',
                '--holdMs=40',
                '--timeoutMs=1500',
                '--scanLimit=64',
                '--pumpPerCall=3',
                '--grantTtlMs=500',
                '--pollIntervalMs=5',
            ]);
        }

        // Start several "pumpers" whose whole job is to concurrently run the pump path.
        $pumpers = [];
        for ($i = 0; $i < 6; $i++) {
            $pumpers[] = $this->startProcess([
                PHP_BINARY, $pumper,
                '--dsn=' . $dsn,
                '--prefix=' . $prefix,
                '--lane=pumper',
                '--globalMax=' . $globalMax,
                '--mode=weighted',
                '--laneWeights=' . json_encode(['auth.login' => 1, 'payments.charge' => 6, 'pumper' => 1]),
                '--durationMs=1200',
                '--scanLimit=64',
                '--pumpPerCall=3',
                '--grantTtlMs=500',
                '--pollIntervalMs=2',
            ]);
        }

        $outputs = [];
        try {
            foreach ($pumpers as $h)   { $outputs[] = $this->waitProcess($h, 10.0); }
            foreach ($consumers as $h) { $outputs[] = $this->waitProcess($h, 10.0); }
            foreach ($waiters as $h)   { $outputs[] = $this->waitProcess($h, 10.0); }
        } finally {
            foreach ($pumpers as $h)   { $this->stopProcess($h); }
            foreach ($consumers as $h) { $this->stopProcess($h); }
            foreach ($waiters as $h)   { $this->stopProcess($h); }
        }

        $maxObserved = (int) ($this->redis()->get($prefix . ':max_in_flight') ?: 0);
        $this->assertLessThanOrEqual($globalMax, $maxObserved, 'Max observed in-flight exceeded global_max (suggests duplicate grant / over-admit).');

        // No leaks: should return to 0 quickly after workers exit.
        usleep(150000);
        $current = (int) ($this->redis()->get($prefix . ':current_in_flight') ?: 0);
        $this->assertSame(0, $current, 'In-flight counter did not return to 0 (possible leak / missing release).');

        // Basic progress: ensure we had some successful acquires and no fatal errors.
        $totalOk = 0;
        $totalErrors = 0;
        foreach ($outputs as $line) {
            $res = json_decode(trim($line), true);
            if (is_array($res)) {
                $totalOk += (int)($res['ok'] ?? 0);
                $totalErrors += (int)($res['errors'] ?? 0);
            }
        }

        $this->assertGreaterThan(0, $totalOk, 'Expected at least some successful acquires during torture run.');
        $this->assertSame(0, $totalErrors, 'Workers reported errors during torture run.');
    }
}

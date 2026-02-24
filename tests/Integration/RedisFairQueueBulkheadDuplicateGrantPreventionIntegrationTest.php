<?php
declare(strict_types=1);

namespace tests\Integration;

final class RedisFairQueueBulkheadDuplicateGrantPreventionIntegrationTest extends IntegrationTestCase
{
    /**
     * This test targets a specific failure mode:
     * two contenders pumping the same queued entry concurrently and both receiving a grant.
     *
     * We assert this cannot happen by enforcing global_max=1 and verifying the observed max
     * in-flight never exceeds 1, using real Redis and two separate PHP processes started
     * behind a barrier so they race.
     *
     * @group integration
     */
    public function testNoDuplicateGrantUnderPumperRace(): void
    {
        $prefix = $this->prefix('dupgrant_' . bin2hex(random_bytes(4)));

        $worker = __DIR__ . '/../fixtures/fair_queue_worker.php';
        $this->assertFileExists($worker);

        $dsn = (string) getenv('GOHANY_CB_TEST_REDIS_DSN');
        $this->assertNotSame('', $dsn, 'GOHANY_CB_TEST_REDIS_DSN must be set (see phpunit.integration.xml.dist).');

        $barrierKey = $prefix . ':barrier';
        $readyA = $prefix . ':ready:A';
        $readyB = $prefix . ':ready:B';

        // Two contenders, one permit globally, each tries once and holds long enough that
        // a duplicate grant would show up as maxObserved=2.
        $argsA = [
            PHP_BINARY, $worker,
            '--dsn=' . $dsn,
            '--prefix=' . $prefix,
            '--lane=payments.charge',
            '--globalMax=1',
            '--mode=weighted',
            '--laneWeights=' . json_encode(['payments.charge' => 5]),
            '--iterations=1',
            '--holdMs=350',
            '--timeoutMs=1200',
            '--scanLimit=64',
            '--pumpPerCall=3',
            '--grantTtlMs=750',
            '--pollIntervalMs=5',
            '--barrierKey=' . $barrierKey,
            '--readyKey=' . $readyA,
        ];

        $argsB = [
            PHP_BINARY, $worker,
            '--dsn=' . $dsn,
            '--prefix=' . $prefix,
            '--lane=payments.charge',
            '--globalMax=1',
            '--mode=weighted',
            '--laneWeights=' . json_encode(['payments.charge' => 5]),
            '--iterations=1',
            '--holdMs=350',
            '--timeoutMs=1200',
            '--scanLimit=64',
            '--pumpPerCall=3',
            '--grantTtlMs=750',
            '--pollIntervalMs=5',
            '--barrierKey=' . $barrierKey,
            '--readyKey=' . $readyB,
        ];

        $procA = $this->startProcess($argsA);
        $procB = $this->startProcess($argsB);

        try {
            // Wait for both processes to signal ready (they should be parked on the barrier).
            $this->waitForKey($readyA, 2.0);
            $this->waitForKey($readyB, 2.0);

            // Release barrier so both race.
            $this->redis()->set($barrierKey, '1');

            $outA = $this->waitProcess($procA, 8.0);
            $outB = $this->waitProcess($procB, 8.0);

            $resA = json_decode(trim($outA), true);
            $resB = json_decode(trim($outB), true);

            $this->assertIsArray($resA, 'Worker A must output JSON.');
            $this->assertIsArray($resB, 'Worker B must output JSON.');
            $this->assertSame('payments.charge', $resA['lane'] ?? null);
            $this->assertSame('payments.charge', $resB['lane'] ?? null);

            // Ensure no concurrent double-admit occurred.
            $maxObserved = (int) $this->redis()->get($prefix . ':max_in_flight');
            $this->assertLessThanOrEqual(1, $maxObserved, 'Duplicate grant would manifest as max_in_flight > global_max.');

            // Both should either succeed (serially) or one succeeds and the other times out/rejects;
            // but neither should error.
            $this->assertSame(0, (int)($resA['errors'] ?? 0));
            $this->assertSame(0, (int)($resB['errors'] ?? 0));
        } finally {
            $this->stopProcess($procA);
            $this->stopProcess($procB);
        }
    }
}

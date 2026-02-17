<?php

declare(strict_types=1);

namespace tests;

use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\HistoryRecord;
use Gohany\Circuitbreaker\Store\InMemoryCircuitHistoryStore;
use Gohany\Circuitbreaker\Store\InMemoryCircuitStateStore;
use Gohany\Circuitbreaker\Store\CircuitState;
use PHPUnit\Framework\TestCase;

final class InMemoryStoresTest extends TestCase
{
    public function testInMemoryCircuitStateStoreCasUsesVersion(): void
    {
        $store = new InMemoryCircuitStateStore();
        $key = new CircuitKey('svc:test', ['a' => 1]);

        $s0 = $store->getState($key);
        $this->assertSame(0, (int) ($s0->meta['version'] ?? -1));

        $expected = $s0;
        $new = new CircuitState(CircuitStateMode::OPEN, 123, 0, []);

        $this->assertTrue($store->casUpdateState($key, $expected, $new));

        $s1 = $store->getState($key);
        $this->assertSame(CircuitStateMode::OPEN, $s1->mode);
        $this->assertSame(123, $s1->openUntilMs);
        $this->assertSame(1, (int) ($s1->meta['version'] ?? -1));

        // Old expected version should now fail.
        $this->assertFalse($store->casUpdateState($key, $expected, $new));
    }

    public function testInMemoryCircuitHistoryStoreTracksConsecutiveFailures(): void
    {
        $store = new InMemoryCircuitHistoryStore();
        $key = new CircuitKey('svc:test', []);

        $h0 = $store->getHistory($key);
        $this->assertSame(0, (int) ($h0->counters['consecutive_failures'] ?? -1));

        $store->record($key, new HistoryRecord(1, false, ['timeout'], 0, []));
        $store->record($key, new HistoryRecord(2, false, ['timeout'], 0, []));

        $h1 = $store->getHistory($key);
        $this->assertSame(2, (int) ($h1->counters['consecutive_failures'] ?? -1));

        $store->record($key, new HistoryRecord(3, true, [], 0, []));
        $h2 = $store->getHistory($key);
        $this->assertSame(0, (int) ($h2->counters['consecutive_failures'] ?? -1));
    }
}

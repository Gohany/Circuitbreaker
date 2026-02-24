<?php

namespace Gohany\Circuitbreaker\Tests;

use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\HistoryRecord;
use Gohany\Circuitbreaker\Store\Pdo\PdoCircuitHistoryStore;
use Gohany\Circuitbreaker\Store\Pdo\PdoCircuitStateStore;
use Gohany\Circuitbreaker\Store\Pdo\PdoProbeGate;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use PDO;
use PHPUnit\Framework\TestCase;

class PdoStoreTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required for PDO store tests.');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $this->pdo->exec("
            CREATE TABLE circuit_states (
                circuit_key VARCHAR(255) PRIMARY KEY,
                mode VARCHAR(32) NOT NULL,
                open_until_ms BIGINT,
                half_open_in_flight INT NOT NULL DEFAULT 0,
                meta_json TEXT,
                version INT NOT NULL DEFAULT 1
            )
        ");

        $this->pdo->exec("
            CREATE TABLE circuit_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                circuit_key VARCHAR(255) NOT NULL,
                ts_ms BIGINT NOT NULL,
                success INT NOT NULL,
                signals_json TEXT,
                duration_ms INT NOT NULL DEFAULT 0,
                attributes_json TEXT
            )
        ");

        $this->pdo->exec("
            CREATE TABLE circuit_probe_gates (
                circuit_key VARCHAR(255) PRIMARY KEY,
                in_flight INT NOT NULL
            )
        ");
    }

    public function testStateStore(): void
    {
        $store = new PdoCircuitStateStore($this->pdo);
        $key = new CircuitKey('test');

        $state = $store->getState($key);
        $this->assertEquals(CircuitStateMode::CLOSED, $state->mode);
        $this->assertEquals(0, $state->meta['version']);

        $newState = new CircuitState(CircuitStateMode::OPEN, 12345, 0, ['foo' => 'bar']);
        $success = $store->casUpdateState($key, $state, $newState);
        
        $this->assertTrue($success);
        
        $updated = $store->getState($key);
        $this->assertEquals(CircuitStateMode::OPEN, $updated->mode);
        $this->assertEquals(12345, $updated->openUntilMs);
        $this->assertEquals('bar', $updated->meta['foo']);
        $this->assertEquals(1, $updated->meta['version']);

        // Test failed CAS
        $failSuccess = $store->casUpdateState($key, $state, $newState);
        $this->assertFalse($failSuccess);
    }

    public function testHistoryStore(): void
    {
        $store = new PdoCircuitHistoryStore($this->pdo);
        $key = new CircuitKey('test');

        $store->record($key, new HistoryRecord(1000, true, [], 0, ['d' => 1]));
        $store->record($key, new HistoryRecord(2000, false, ['timeout'], 0, ['d' => 2]));

        $history = $store->getHistory($key);
        $window = $history->window;

        $this->assertCount(2, $window);
        $this->assertSame(1000, $window[0]->tsMs);
        $this->assertTrue($window[0]->success);
        $this->assertSame(['d' => 1], $window[0]->attributes);

        $this->assertSame(2000, $window[1]->tsMs);
        $this->assertFalse($window[1]->success);
        $this->assertSame(['timeout'], $window[1]->signals);
    }

    public function testProbeGate(): void
    {
        $gate = new PdoProbeGate($this->pdo);
        $key = new CircuitKey('test');
        $config = new ProbeGateConfig(1, true);

        $res1 = $gate->acquire($key, $config, 5000);
        $this->assertTrue($res1->acquired);

        $res2 = $gate->acquire($key, $config, 5100);
        $this->assertFalse($res2->acquired);

        $gate->release($key);
        $res3 = $gate->acquire($key, $config, 6100);
        $this->assertTrue($res3->acquired);
    }
}

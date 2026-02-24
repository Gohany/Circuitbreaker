<?php

declare(strict_types=1);

namespace tests\Store\Pdo;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use Gohany\Circuitbreaker\Store\Pdo\PdoProbeGate;
use PHPUnit\Framework\TestCase;

final class PdoProbeGateTest extends TestCase
{
    public function testAcquireAndReleaseRespectsMaxInFlight(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE circuit_probe_gates (circuit_key TEXT PRIMARY KEY, in_flight INT NOT NULL)');

        $gate = new PdoProbeGate($pdo);
        $key = new CircuitKey('depA');
        $config = new ProbeGateConfig(2);

        $r1 = $gate->acquire($key, $config, 0);
        self::assertTrue($r1->acquired);
        self::assertSame(1, $r1->inFlight);

        $r2 = $gate->acquire($key, $config, 0);
        self::assertTrue($r2->acquired);
        self::assertSame(2, $r2->inFlight);

        $r3 = $gate->acquire($key, $config, 0);
        self::assertFalse($r3->acquired);
        self::assertSame(2, $r3->inFlight);
        self::assertSame(250, $r3->retryAfterMs);

        $gate->release($key);
        $gate->release($key);

        $r4 = $gate->acquire($key, $config, 0);
        self::assertTrue($r4->acquired);
        self::assertSame(1, $r4->inFlight);
    }
}

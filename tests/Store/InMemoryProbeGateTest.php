<?php

declare(strict_types=1);

namespace tests\Store;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\InMemoryProbeGate;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use PHPUnit\Framework\TestCase;

final class InMemoryProbeGateTest extends TestCase
{
    public function testAcquireSaturatesAndReleaseFreesCapacity(): void
    {
        $gate = new InMemoryProbeGate();
        $key = new CircuitKey('op', ['a' => 1]);
        $cfg = new ProbeGateConfig(2);

        $r1 = $gate->acquire($key, $cfg, 0);
        $this->assertTrue($r1->acquired);
        $this->assertSame(1, $r1->inFlight);

        $r2 = $gate->acquire($key, $cfg, 0);
        $this->assertTrue($r2->acquired);
        $this->assertSame(2, $r2->inFlight);

        $r3 = $gate->acquire($key, $cfg, 0);
        $this->assertFalse($r3->acquired);
        $this->assertSame(2, $r3->inFlight);
        $this->assertGreaterThan(0, $r3->retryAfterMs);

        $gate->release($key);
        $r4 = $gate->acquire($key, $cfg, 0);
        $this->assertTrue($r4->acquired);
        $this->assertSame(2, $r4->inFlight);
    }

    public function testReleaseDoesNotGoNegative(): void
    {
        $gate = new InMemoryProbeGate();
        $key = new CircuitKey('op', ['a' => 1]);
        $cfg = new ProbeGateConfig(1);

        $gate->acquire($key, $cfg, 0);
        $gate->release($key);
        $gate->release($key);

        $r = $gate->acquire($key, $cfg, 0);
        $this->assertTrue($r->acquired);
        $this->assertSame(1, $r->inFlight);
    }
}

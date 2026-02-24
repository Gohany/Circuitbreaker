<?php

declare(strict_types=1);

namespace tests\Store\Apcu;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\Apcu\ApcuCircuitHistoryStore;
use Gohany\Circuitbreaker\Store\Apcu\ApcuCircuitStateStore;
use Gohany\Circuitbreaker\Store\Apcu\ApcuProbeGate;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use PHPUnit\Framework\TestCase;

final class ApcuStoresTest extends TestCase
{
    public function testStoresThrowClearExceptionWhenApcuIsMissing(): void
    {
        if (function_exists('apcu_fetch')) {
            self::markTestSkipped('ext-apcu is available in this environment; this test targets the missing-ext behavior.');
        }

        $key = new CircuitKey('op');

        $state = new ApcuCircuitStateStore();
        $this->expectException(\RuntimeException::class);
        $state->getState($key);
    }

    public function testProbeGateThrowsClearExceptionWhenApcuIsMissing(): void
    {
        if (function_exists('apcu_fetch')) {
            self::markTestSkipped('ext-apcu is available in this environment; this test targets the missing-ext behavior.');
        }

        $gate = new ApcuProbeGate();
        $this->expectException(\RuntimeException::class);
        $gate->acquire(new CircuitKey('op'), new ProbeGateConfig(1), 0);
    }

    public function testHistoryStoreThrowsClearExceptionWhenApcuIsMissing(): void
    {
        if (function_exists('apcu_fetch')) {
            self::markTestSkipped('ext-apcu is available in this environment; this test targets the missing-ext behavior.');
        }

        $hist = new ApcuCircuitHistoryStore();
        $this->expectException(\RuntimeException::class);
        $hist->getHistory(new CircuitKey('op'));
    }
}

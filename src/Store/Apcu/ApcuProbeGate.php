<?php

namespace Gohany\Circuitbreaker\Store\Apcu;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use Gohany\Circuitbreaker\Store\ProbeGateInterface;
use Gohany\Circuitbreaker\Store\ProbeGateResult;

final class ApcuProbeGate implements ProbeGateInterface
{
    private string $prefix;

    private function assertApcuAvailable(): void
    {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_entry') || !function_exists('apcu_delete')) {
            throw new \RuntimeException('APCu is not available: please install/enable ext-apcu.');
        }
    }

    public function __construct(string $prefix = 'cb:probe:')
    {
        $this->prefix = $prefix;
    }

    public function acquire(CircuitKey $key, ProbeGateConfig $config, int $nowMs): ProbeGateResult
    {
        $this->assertApcuAvailable();
        $k = $this->prefix . $key->id();

        $max = max(1, (int) $config->maxInFlight);
        $ttlSeconds = 3600; // best-effort safety TTL in case of leaked permits

        $granted = false;
        $cur = 0;

        apcu_entry($k, function ($key, $existing) use ($max, &$granted, &$cur) {
            $existing = is_int($existing) ? $existing : 0;
            $cur = $existing;
            if ($existing < $max) {
                $granted = true;
                $cur = $existing + 1;
                return $existing + 1;
            }
            return $existing;
        }, $ttlSeconds);

        if ($granted) {
            return new ProbeGateResult(true, 'half_open', $cur, 0);
        }

        return new ProbeGateResult(false, 'half_open', $cur, 250);
    }

    public function release(CircuitKey $key): void
    {
        $this->assertApcuAvailable();
        $k = $this->prefix . $key->id();

        apcu_entry($k, function ($key, $existing) {
            $existing = is_int($existing) ? $existing : 0;
            $next = $existing > 0 ? $existing - 1 : 0;
            return $next;
        }, 3600);

        $cur = apcu_fetch($k);
        if ($cur === 0) {
            apcu_delete($k);
        }
    }
}

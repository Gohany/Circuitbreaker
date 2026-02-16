<?php

namespace Gohany\Circuitbreaker\Store\Apcu;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use Gohany\Circuitbreaker\Store\ProbeGateInterface;
use Gohany\Circuitbreaker\Store\ProbeGateResult;

final class ApcuProbeGate implements ProbeGateInterface
{
    private string $prefix;

    public function __construct(string $prefix = 'cb:probe:')
    {
        $this->prefix = $prefix;
    }

    public function acquire(CircuitKey $key, ProbeGateConfig $config, int $nowMs): ProbeGateResult
    {
        $k = $this->prefix . $key->id();
        $ttlSeconds = (int) ceil($config->timeoutMs / 1000);
        if ($ttlSeconds < 1) $ttlSeconds = 1;

        // apcu_add returns false if the key already exists
        if (apcu_add($k, $nowMs + $config->timeoutMs, $ttlSeconds)) {
            return ProbeGateResult::granted();
        }

        // Check if it's expired (in case TTL didn't trigger yet or we want to be safe)
        $expiresAt = apcu_fetch($k);
        if ($expiresAt !== false && $nowMs > $expiresAt) {
            // Expired, try to re-acquire (not fully atomic re-acquire but good enough for single server APCu)
            // APCu doesn't have a good way to atomically replace if value matches without apcu_entry
            $granted = false;
            apcu_entry($k, function($key, $existing) use ($nowMs, $config, &$granted, $ttlSeconds) {
                if ($existing === false || $nowMs > $existing) {
                    $granted = true;
                    return $nowMs + $config->timeoutMs;
                }
                return $existing;
            }, $ttlSeconds);

            if ($granted) {
                return ProbeGateResult::granted();
            }
        }

        return ProbeGateResult::denied();
    }

    public function release(CircuitKey $key): void
    {
        apcu_delete($this->prefix . $key->id());
    }
}

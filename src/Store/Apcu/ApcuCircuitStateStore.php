<?php

namespace Gohany\Circuitbreaker\Store\Apcu;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\CircuitStateStoreInterface;
use Gohany\Circuitbreaker\Consts\CircuitStateMode;

final class ApcuCircuitStateStore implements CircuitStateStoreInterface
{
    private string $prefix;
    private int $ttlSeconds;

    public function __construct(string $prefix = 'cb:state:', int $ttlSeconds = 604800)
    {
        $this->prefix = $prefix;
        $this->ttlSeconds = $ttlSeconds;
    }

    public function getState(CircuitKey $key): CircuitState
    {
        $k = $this->prefix . $key->id();
        $raw = apcu_fetch($k);

        if ($raw === false) {
            return new CircuitState(CircuitStateMode::CLOSED, null, 0, ['version' => 0]);
        }

        return new CircuitState(
            $raw['mode'],
            $raw['open_until_ms'],
            $raw['half_open_in_flight'],
            $raw['meta']
        );
    }

    public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool
    {
        $k = $this->prefix . $key->id();
        
        $expectedVersion = isset($expected->meta['version']) ? (int) $expected->meta['version'] : 0;
        $newVersion = $expectedVersion + 1;
        
        $newMeta = $new->meta;
        $newMeta['version'] = $newVersion;
        
        $newData = [
            'mode' => $new->mode,
            'open_until_ms' => $new->openUntilMs,
            'half_open_in_flight' => $new->halfOpenInFlight,
            'meta' => $newMeta,
        ];

        if ($expectedVersion === 0) {
            // Attempt to add (only if not exists)
            return apcu_add($k, $newData, $this->ttlSeconds);
        }

        // APCu doesn't have a built-in "CAS for complex array values" based on a sub-field.
        // It has apcu_cas for integers.
        // We can simulate it with apcu_entry or by fetching and checking version (less atomic but often okay for APCu which is single-server).
        // However, to be as atomic as possible:
        
        $success = false;
        apcu_entry($k, function($key, $existing) use ($expectedVersion, $newData, &$success) {
            if ($existing !== false && isset($existing['meta']['version']) && (int)$existing['meta']['version'] === $expectedVersion) {
                $success = true;
                return $newData;
            }
            return $existing;
        }, $this->ttlSeconds);

        return $success;
    }
}

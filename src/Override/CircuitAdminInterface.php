<?php

namespace Gohany\Circuitbreaker\Override;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\CircuitState;

interface CircuitAdminInterface
{
    /**
     * @param array<string,mixed> $meta
     */
    public function forceState(CircuitKey $key, CircuitState $state, $ttlMs, array $meta = []): void;

    public function clearForcedState(CircuitKey $key): void;

    /**
     * @param array<string,mixed> $meta
     */
    public function resetHistory(CircuitKey $key, array $meta = []): void;

    /**
     * @param array<string,mixed> $meta
     */
    public function forgiveHistory(CircuitKey $key, $sinceTsMs, array $meta = []): void;
}

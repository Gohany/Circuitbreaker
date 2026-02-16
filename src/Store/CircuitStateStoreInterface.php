<?php

namespace Gohany\Circuitbreaker\Store;

use Gohany\Circuitbreaker\Core\CircuitKey;

interface CircuitStateStoreInterface
{
    public function getState(CircuitKey $key): CircuitState;

    public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool;
}

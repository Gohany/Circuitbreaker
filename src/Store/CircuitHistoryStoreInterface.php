<?php

namespace Gohany\Circuitbreaker\Store;

use Gohany\Circuitbreaker\Core\CircuitKey;

interface CircuitHistoryStoreInterface
{
    public function getHistory(CircuitKey $key): CircuitHistory;

    public function record(CircuitKey $key, HistoryRecord $record): void;
}

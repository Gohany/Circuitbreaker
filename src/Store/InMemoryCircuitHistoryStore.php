<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Store;

use Gohany\Circuitbreaker\Core\CircuitKey;

/**
 * In-process circuit history store.
 *
 * Tracks only the counters needed by bundled policies (currently `consecutive_failures`).
 * Intended for local development and sanity checks. Not suitable for multi-process deployments.
 */
final class InMemoryCircuitHistoryStore implements CircuitHistoryStoreInterface
{
    /** @var array<string,array{counters:array<string,mixed>, window:array<int,mixed>}> */
    private array $data = [];

    public function getHistory(CircuitKey $key): CircuitHistory
    {
        $id = $this->keyId($key);
        if (!isset($this->data[$id])) {
            $this->data[$id] = [
                'counters' => [
                    'consecutive_failures' => 0,
                ],
                'window' => [],
            ];
        }

        return new CircuitHistory($this->data[$id]['counters'], $this->data[$id]['window']);
    }

    public function record(CircuitKey $key, HistoryRecord $record): void
    {
        $id = $this->keyId($key);
        $h = $this->getHistory($key);
        $counters = $h->counters;

        $cf = (int) ($counters['consecutive_failures'] ?? 0);
        $counters['consecutive_failures'] = $record->success ? 0 : ($cf + 1);

        $this->data[$id] = [
            'counters' => $counters,
            'window' => $h->window,
        ];
    }

    private function keyId(CircuitKey $key): string
    {
        $dim = $key->dimensions;
        ksort($dim);
        return $key->name . '|' . json_encode($dim);
    }
}

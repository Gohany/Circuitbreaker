<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Store;

use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Core\CircuitKey;

/**
 * In-process circuit state store.
 *
 * Intended for local development and sanity checks. Not suitable for multi-process deployments.
 */
final class InMemoryCircuitStateStore implements CircuitStateStoreInterface
{
    /** @var array<string,CircuitState> */
    private array $states = [];

    public function getState(CircuitKey $key): CircuitState
    {
        $id = $this->keyId($key);
        if (!isset($this->states[$id])) {
            $this->states[$id] = new CircuitState(CircuitStateMode::CLOSED, null, 0, ['version' => 0]);
        }

        return $this->states[$id];
    }

    public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool
    {
        $id = $this->keyId($key);

        $current = $this->getState($key);

        $expectedVersion = isset($expected->meta['version']) ? (int) $expected->meta['version'] : 0;
        $currentVersion = isset($current->meta['version']) ? (int) $current->meta['version'] : 0;

        if ($currentVersion !== $expectedVersion) {
            return false;
        }

        $newState = new CircuitState(
            $new->mode,
            $new->openUntilMs,
            $new->halfOpenInFlight,
            $new->meta
        );
        $newState->meta['version'] = $expectedVersion + 1;

        $this->states[$id] = $newState;

        return true;
    }

    private function keyId(CircuitKey $key): string
    {
        $dim = $key->dimensions;
        ksort($dim);
        return $key->name . '|' . json_encode($dim);
    }
}

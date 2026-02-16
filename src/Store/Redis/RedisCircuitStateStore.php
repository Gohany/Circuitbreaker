<?php

namespace Gohany\Circuitbreaker\Store\Redis;

use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\CircuitStateStoreInterface;

final class RedisCircuitStateStore implements CircuitStateStoreInterface
{
    private RedisClientInterface $redis;
    private RedisKeyBuilder $keys;
    private int $defaultTtlMs;

    public function __construct(RedisClientInterface $redis, RedisKeyBuilder $keys, int $defaultTtlMs = 604800000)
    {
        $this->redis = $redis;
        $this->keys = $keys;
        $this->defaultTtlMs = $defaultTtlMs;
    }

    public function getState(CircuitKey $key): CircuitState
    {
        $k = $this->keys->stateKey($key);
        $raw = $this->redis->hGetAll($k);

        if (empty($raw)) {
            return new CircuitState(CircuitStateMode::CLOSED, null, 0, ['version' => 0]);
        }

        $mode = isset($raw['mode']) ? (string) $raw['mode'] : CircuitStateMode::CLOSED;

        $openUntil = null;
        if (isset($raw['open_until_ms']) && $raw['open_until_ms'] !== '') {
            $openUntil = (int) $raw['open_until_ms'];
        }

        $inFlight = isset($raw['half_open_in_flight']) ? (int) $raw['half_open_in_flight'] : 0;

        $meta = [];
        if (isset($raw['meta_json']) && $raw['meta_json'] !== '') {
            $decoded = json_decode($raw['meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $meta['version'] = isset($raw['version']) ? (int) $raw['version'] : 0;

        return new CircuitState($mode, $openUntil, $inFlight, $meta);
    }

    public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool
    {
        $k = $this->keys->stateKey($key);

        $expectedVersion = isset($expected->meta['version']) ? (int) $expected->meta['version'] : 0;
        $openUntil = $new->openUntilMs === null ? '' : (string) (int) $new->openUntilMs;
        $metaJson = json_encode($new->meta);

        $pexp = $this->ttlForStateMs($new);

        $res = $this->redis->eval(LuaScripts::CAS_UPDATE_STATE, [$k], [
            (string) $expectedVersion,
            (string) $new->mode,
            $openUntil,
            (string) (int) $new->halfOpenInFlight,
            (string) $metaJson,
            (string) $pexp,
        ]);

        return (int) $res === 1;
    }

    private function ttlForStateMs(CircuitState $state): int
    {
        if ($state->mode === CircuitStateMode::OPEN && $state->openUntilMs !== null) {
            $now = (int) floor(microtime(true) * 1000);
            return max(60000, ($state->openUntilMs - $now) + 60000);
        }

        if ($state->mode === CircuitStateMode::HALF_OPEN) {
            return 3600000;
        }

        return $this->defaultTtlMs;
    }
}

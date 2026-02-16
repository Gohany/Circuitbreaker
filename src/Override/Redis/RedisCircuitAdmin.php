<?php

namespace Gohany\Circuitbreaker\Override\Redis;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Override\CircuitAdminInterface;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\CircuitStateStoreInterface;
use Gohany\Circuitbreaker\Store\Redis\RedisClientInterface;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;
use Gohany\Circuitbreaker\Util\Time;
use Psr\Clock\ClockInterface;

final class RedisCircuitAdmin implements CircuitAdminInterface
{
    private RedisClientInterface $redis;
    private RedisKeyBuilder $keys;
    private RedisOverrideStore $overrides;
    private CircuitStateStoreInterface $stateStore;
    private ClockInterface $clock;

    public function __construct(
        RedisClientInterface $redis,
        RedisKeyBuilder $keys,
        RedisOverrideStore $overrides,
        CircuitStateStoreInterface $stateStore,
        ClockInterface $clock
    ) {
        $this->redis = $redis;
        $this->keys = $keys;
        $this->overrides = $overrides;
        $this->stateStore = $stateStore;
        $this->clock = $clock;
    }

    public function forceState(CircuitKey $key, CircuitState $state, $ttlMs, array $meta = []): void
    {
        $now = Time::toUnixMs($this->clock->now());
        $ttlMs = (int) $ttlMs;
        $until = $ttlMs > 0 ? ($now + $ttlMs) : null;

        $fields = [
            'forced_mode' => (string) $state->mode,
            'forced_until_ms' => $until === null ? '' : (string) $until,
            'reason' => isset($meta['reason']) ? (string) $meta['reason'] : 'admin_force_state',
            'meta_json' => json_encode($meta),
            'force_allow' => '',
            'force_deny' => '',
        ];

        $this->overrides->set($key, $fields, $ttlMs > 0 ? $ttlMs : null);

        $this->bestEffortSetState($key, $state, 3);
    }

    public function clearForcedState(CircuitKey $key): void
    {
        $this->overrides->clear($key);
    }

    public function resetHistory(CircuitKey $key, array $meta = []): void
    {
        $this->redis->del($this->keys->countersKey($key));
        $this->deleteByScan($this->keys->bucketPattern($key));
    }

    public function forgiveHistory(CircuitKey $key, $sinceTsMs, array $meta = []): void
    {
        $sinceTsMs = (int) $sinceTsMs;
        $sinceMinute = (int) floor($sinceTsMs / 60000);

        $pattern = $this->keys->bucketPattern($key);
        $it = null;

        do {
            $batch = $this->redis->scan($it, $pattern, 250);
            if (!is_array($batch) || empty($batch)) {
                continue;
            }

            foreach ($batch as $redisKey) {
                $pos = strrpos($redisKey, ':b:');
                if ($pos === false) {
                    continue;
                }
                $minute = (int) substr($redisKey, $pos + 3);
                if ($minute >= $sinceMinute) {
                    $this->redis->del($redisKey);
                }
            }
        } while ($it !== 0 && $it !== null);

        $this->redis->hMSet($this->keys->countersKey($key), ['consecutive_failures' => '0']);
    }

    private function bestEffortSetState(CircuitKey $key, CircuitState $desired, int $tries): void
    {
        for ($i = 0; $i < $tries; $i++) {
            $cur = $this->stateStore->getState($key);
            if ($this->stateStore->casUpdateState($key, $cur, $desired)) {
                return;
            }
        }
    }

    private function deleteByScan(string $pattern): void
    {
        $it = null;
        do {
            $batch = $this->redis->scan($it, $pattern, 250);
            if (!is_array($batch) || empty($batch)) {
                continue;
            }
            foreach ($batch as $k) {
                $this->redis->del($k);
            }
        } while ($it !== 0 && $it !== null);
    }
}

<?php

namespace Gohany\Circuitbreaker\Override\Redis;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\Redis\RedisClientInterface;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;

final class RedisOverrideStore
{
    private RedisClientInterface $redis;
    private RedisKeyBuilder $keys;

    public function __construct(RedisClientInterface $redis, RedisKeyBuilder $keys)
    {
        $this->redis = $redis;
        $this->keys = $keys;
    }

    /**
     * @return array<string,string>
     */
    public function get(CircuitKey $key): array
    {
        $k = $this->keys->overrideKey($key);
        $raw = $this->redis->hGetAll($k);
        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<string,string> $fields
     */
    public function set(CircuitKey $key, array $fields, ?int $ttlMs): void
    {
        $k = $this->keys->overrideKey($key);
        $this->redis->hMSet($k, $fields);

        if ($ttlMs !== null && $ttlMs > 0) {
            $this->redis->pExpire($k, $ttlMs);
        }
    }

    public function clear(CircuitKey $key): void
    {
        $k = $this->keys->overrideKey($key);
        $this->redis->del($k);
    }
}

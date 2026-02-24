<?php
declare(strict_types=1);

namespace Gohany\Circuitbreaker\Redis;

use Redis;
use RedisException;

/**
 * Detects Redis Lua capabilities and caches the result:
 *  - in-process (static) for quick reuse in the same PHP worker
 *  - in Redis so the whole fleet only probes once (per endpoint hash), without deploy IDs.
 */
final class RedisLuaCapabilitiesDetector
{
    /** @var array<string, array{cap:string, expires:int}> */
    private static array $localCache = [];

    private Redis $redis;
    private string $capabilityKey;
    private int $redisTtlSeconds;
    private int $localTtlSeconds;
    private bool $enableRedisCache;

    public function __construct(
        Redis $redis,
        string $capabilityKey,
        int $redisTtlSeconds = 2592000,
        int $localTtlSeconds = 3600,
        bool $enableRedisCache = true
    ) {
        $this->redis = $redis;
        $this->capabilityKey = $capabilityKey;
        $this->redisTtlSeconds = $redisTtlSeconds;
        $this->localTtlSeconds = $localTtlSeconds;
        $this->enableRedisCache = $enableRedisCache;
    }

    public function getCapability(): string
    {
        $now = time();

        // 1) local cache
        $row = self::$localCache[$this->capabilityKey] ?? null;
        if ($row && $row['expires'] >= $now) {
            return $row['cap'];
        }

        // 2) shared Redis cache
        if ($this->enableRedisCache) {
            try {
                $cap = $this->redis->get($this->capabilityKey);
                if (is_string($cap) && $cap !== '') {
                    $this->storeLocal($cap);
                    return $cap;
                }
            } catch (RedisException $e) {
                // Ignore and fall back to probing.
            }
        }

        // 3) probe
        $cap = $this->probe();

        // 4) write shared cache best-effort
        if ($this->enableRedisCache) {
            try {
                $this->redis->set($this->capabilityKey, $cap, ['ex' => $this->redisTtlSeconds]);
            } catch (RedisException $e) {
                // ignore
            }
        }

        $this->storeLocal($cap);
        return $cap;
    }

    private function storeLocal(string $cap): void
    {
        self::$localCache[$this->capabilityKey] = [
            'cap' => $cap,
            'expires' => time() + $this->localTtlSeconds,
        ];
    }

    private function probe(): string
    {
        // Prefer SCRIPT LOAD (checks SCRIPT command is allowed)
        try {
            $sha = $this->redis->script('load', 'return 1');
            if (is_string($sha) && $sha !== '') {
                // Confirm EVALSHA works
                $res = $this->redis->evalSha($sha, [], 0);
                if ($res === 1 || $res === '1') {
                    return RedisLuaCapability::SCRIPT_CACHEABLE;
                }
            }
        } catch (RedisException $e) {
            // fall through
        }

        // Try EVAL
        try {
            $res = $this->redis->eval('return 1', [], 0);
            if ($res === 1 || $res === '1') {
                return RedisLuaCapability::EVAL_ONLY;
            }
        } catch (RedisException $e) {
            // fall through
        }

        return RedisLuaCapability::NO_LUA;
    }
}

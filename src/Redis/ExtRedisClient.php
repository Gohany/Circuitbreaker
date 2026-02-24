<?php
declare(strict_types=1);

namespace Gohany\Circuitbreaker\Redis;

use Redis;
use RedisException;

/**
 * Redis client wrapper used by the circuitbreaker project.
 *
 * This patch adds:
 *  - Lua capability autodetection (cached in Redis + per-process)
 *  - Central runLua() helper that prefers EVALSHA with NOSCRIPT reload fallback
 *
 * IMPORTANT:
 *  - Capability probing is lazy (first use), never at Symfony compile-time.
 *  - Capability cache key is stable per endpoint hash (no deploy IDs required).
 */
final class ExtRedisClient
{
    /** @var array<string, string> */
    private array $shaCache = [];

    private ?RedisLuaCapabilitiesDetector $capDetector = null;

    private Redis $redis;
    private ?string $luaCapabilityKey;
    private int $luaCapabilityTtlSeconds;
    private int $luaCapabilityLocalTtlSeconds;
    private bool $luaCapabilityRedisCacheEnabled;

    public function __construct(
        Redis $redis,
        ?string $luaCapabilityKey = null,
        int $luaCapabilityTtlSeconds = 2592000,
        int $luaCapabilityLocalTtlSeconds = 3600,
        bool $luaCapabilityRedisCacheEnabled = true
    ) {
        $this->redis = $redis;
        $this->luaCapabilityKey = $luaCapabilityKey;
        $this->luaCapabilityTtlSeconds = $luaCapabilityTtlSeconds;
        $this->luaCapabilityLocalTtlSeconds = $luaCapabilityLocalTtlSeconds;
        $this->luaCapabilityRedisCacheEnabled = $luaCapabilityRedisCacheEnabled;
    }

    public function raw(): Redis
    {
        return $this->redis;
    }

    /**
     * Run a Lua script with automatic capability selection:
     *  - SCRIPT_CACHEABLE: EVALSHA (cached) w/ NOSCRIPT -> SCRIPT LOAD retry
     *  - EVAL_ONLY: EVAL (send full script)
     *  - NO_LUA: throws RedisLuaNotAvailableException
     *
     * @param list<string> $keys
     * @param list<mixed> $args
     * @return mixed
     */
    public function runLua(string $name, string $lua, array $keys, array $args)
    {
        $cap = $this->getLuaCapability();

        $numKeys = count($keys);
        $params = array_merge($keys, $args);

        if ($cap === RedisLuaCapability::SCRIPT_CACHEABLE) {
            $sha = $this->shaCache[$name] ?? null;
            if (!$sha) {
                $sha = $this->redis->script('load', $lua);
                if (!is_string($sha) || $sha === '') {
                    throw new \RuntimeException('Redis SCRIPT LOAD did not return a valid SHA for script: ' . $name);
                }
                $this->shaCache[$name] = $sha;
            }

            try {
                return $this->redis->evalSha((string)$sha, $params, $numKeys);
            } catch (RedisException $e) {
                // Failover / restart / SCRIPT FLUSH can cause NOSCRIPT even when capability is script_cacheable.
                if (stripos($e->getMessage(), 'NOSCRIPT') !== false) {
                    $sha = $this->redis->script('load', $lua);
                    if (!is_string($sha) || $sha === '') {
                        throw new \RuntimeException('Redis SCRIPT LOAD did not return a valid SHA for script reload: ' . $name);
                    }
                    $this->shaCache[$name] = $sha;
                    return $this->redis->evalSha($sha, $params, $numKeys);
                }
                throw $e;
            }
        }

        if ($cap === RedisLuaCapability::EVAL_ONLY) {
            return $this->redis->eval($lua, $params, $numKeys);
        }

        throw new RedisLuaNotAvailableException('Redis Lua is not available for this endpoint.');
    }

    public function getLuaCapability(): string
    {
        if ($this->capDetector === null) {
            if ($this->luaCapabilityKey !== null) {
                $key = $this->luaCapabilityKey;
            } else {
                $key = 'cb:redis:lua_cap:default';
                // Best-effort: derive an endpoint-specific cache key to avoid cross-endpoint poisoning.
                try {
                    $host = method_exists($this->redis, 'getHost') ? (string) $this->redis->getHost() : 'unknown';
                    $port = method_exists($this->redis, 'getPort') ? (int) $this->redis->getPort() : 0;
                    $db = method_exists($this->redis, 'getDbNum') ? (int) $this->redis->getDbNum() : 0;
                    $key = sprintf('cb:redis:lua_cap:%s:%d:%d', $host, $port, $db);
                } catch (RedisException $e) {
                    // Keep legacy default key.
                }
            }
            $this->capDetector = new RedisLuaCapabilitiesDetector(
                $this->redis,
                $key,
                $this->luaCapabilityTtlSeconds,
                $this->luaCapabilityLocalTtlSeconds,
                $this->luaCapabilityRedisCacheEnabled
            );
        }

        return $this->capDetector->getCapability();
    }

    // -------------------------
    // Convenience helpers used by other components can continue to wrap $this->redis
    // -------------------------

    /** @return mixed */
    public function get(string $key)
    {
        return $this->redis->get($key);
    }

    /** @return bool */
    public function set(string $key, $value, array $options = []): bool
    {
        return (bool) $this->redis->set($key, $value, $options);
    }

    /** @return int */
    public function del($keys): int
    {
        return $this->redis->del($keys);
    }
}

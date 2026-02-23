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

    public function __construct(
        private readonly Redis $redis,
        private readonly ?string $luaCapabilityKey = null,
        private readonly int $luaCapabilityTtlSeconds = 2592000,
        private readonly int $luaCapabilityLocalTtlSeconds = 3600,
        private readonly bool $luaCapabilityRedisCacheEnabled = true,
    ) {}

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
                $this->shaCache[$name] = (string) $sha;
            }

            try {
                return $this->redis->evalSha((string)$sha, $params, $numKeys);
            } catch (RedisException $e) {
                // Failover / restart / SCRIPT FLUSH can cause NOSCRIPT even when capability is script_cacheable.
                if (stripos($e->getMessage(), 'NOSCRIPT') !== false) {
                    $sha = $this->redis->script('load', $lua);
                    $this->shaCache[$name] = (string) $sha;
                    return $this->redis->evalSha((string)$sha, $params, $numKeys);
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
            $key = $this->luaCapabilityKey ?? 'cb:redis:lua_cap:default';
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

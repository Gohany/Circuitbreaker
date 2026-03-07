<?php

namespace Gohany\Circuitbreaker\Store\Redis;

final class ExtRedisClient implements RedisClientInterface
{
    /** @var \Redis */
    private $redis;

    public function __construct(\Redis $redis)
    {
        $this->redis = $redis;
    }

    public function eval(string $script, array $keys = [], array $args = [])
    {
        $params = array_merge($keys, $args);
        return $this->redis->eval($script, $params, count($keys));
    }

    public function get($key) { return $this->redis->get($key); }

    public function set($key, $value, array $options = [])
    {
        return $this->redis->set($key, $value, $options);
    }

    public function del($key) { return $this->redis->del($key); }

    public function hGetAll($key) { return $this->redis->hGetAll($key); }

    public function hMSet($key, array $fields) { return $this->redis->hMSet($key, $fields); }

    public function hIncrBy($key, $field, $by) { return $this->redis->hIncrBy($key, $field, $by); }

    public function expire($key, $ttlSeconds) { return $this->redis->expire($key, $ttlSeconds); }

    public function pExpire($key, $ttlMs) { return $this->redis->pExpire($key, $ttlMs); }

    public function exists($key) { return (bool) $this->redis->exists($key); }

    public function scan(&$iterator, $pattern, $count = 100)
    {
        return $this->redis->scan($iterator, $pattern, (int) $count);
    }
}

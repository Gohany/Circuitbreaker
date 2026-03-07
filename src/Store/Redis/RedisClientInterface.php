<?php

namespace Gohany\Circuitbreaker\Store\Redis;

use Gohany\Circuitbreaker\Contracts\RedisClientInterface as ContractsRedisClientInterface;

interface RedisClientInterface extends ContractsRedisClientInterface
{
    public function eval(string $script, array $keys = [], array $args = []);
    public function get($key);
    /**
     * @param array<string,mixed> $options
     */
    public function set($key, $value, array $options = []);
    public function del($key);
    /**
     * @return array<string,string>
     */
    public function hGetAll($key);
    /**
     * @param array<string,string> $fields
     */
    public function hMSet($key, array $fields);
    public function hIncrBy($key, $field, $by);
    public function expire($key, $ttlSeconds);
    public function pExpire($key, $ttlMs);
    public function exists($key);
    public function scan(&$iterator, $pattern, $count = 100);
}

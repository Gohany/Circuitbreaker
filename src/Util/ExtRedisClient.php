<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Util;

use Gohany\Circuitbreaker\Contracts\RedisClientInterface;

/**
 * Adapter for ext-redis (\Redis).
 */
final class ExtRedisClient implements RedisClientInterface
{
    /** @var \Redis */
    private $redis;

    public function __construct(\Redis $redis)
    {
        $this->redis = $redis;
    }

    /**
     * @param array<int,string> $keys
     * @param array<int,string|int|float> $args
     * @return mixed
     */
    public function eval(string $script, array $keys, array $args)
    {
        // ext-redis expects args merged: [keys..., args...]
        $numKeys = count($keys);
        $argv = array_merge($keys, $args);
        return $this->redis->eval($script, $argv, $numKeys);
    }
}

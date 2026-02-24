<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Contracts;

interface RedisClientInterface
{
    /**
     * @param string $script
     * @param array<int,string> $keys
     * @param array<int,string|int|float> $args
     * @return mixed
     */
    public function eval(string $script, array $keys, array $args);
}

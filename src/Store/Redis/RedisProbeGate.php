<?php

namespace Gohany\Circuitbreaker\Store\Redis;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use Gohany\Circuitbreaker\Store\ProbeGateInterface;
use Gohany\Circuitbreaker\Store\ProbeGateResult;

final class RedisProbeGate implements ProbeGateInterface
{
    private RedisClientInterface $redis;
    private RedisKeyBuilder $keys;
    private int $stateTtlMs;

    public function __construct(RedisClientInterface $redis, RedisKeyBuilder $keys, int $stateTtlMs = 604800000)
    {
        $this->redis = $redis;
        $this->keys = $keys;
        $this->stateTtlMs = $stateTtlMs;
    }

    public function acquire(CircuitKey $key, ProbeGateConfig $config, int $nowMs): ProbeGateResult
    {
        $stateKey = $this->keys->stateKey($key);

        $res = $this->redis->eval(LuaScripts::ACQUIRE_PROBE, [$stateKey], [
            (string) $nowMs,
            (string) $config->maxInFlight,
            '',
            $config->allowOpenExpiredToHalfOpen ? '1' : '0',
            (string) $this->stateTtlMs,
        ]);

        $acquired = isset($res[0]) ? ((int) $res[0] === 1) : false;
        $mode = isset($res[1]) ? (string) $res[1] : 'closed';
        $inFlight = isset($res[3]) ? (int) $res[3] : 0;
        $retryAfter = isset($res[4]) ? (int) $res[4] : 0;

        return new ProbeGateResult($acquired, $mode, $inFlight, $retryAfter);
    }

    public function release(CircuitKey $key): void
    {
        $stateKey = $this->keys->stateKey($key);
        $this->redis->eval(LuaScripts::RELEASE_PROBE, [$stateKey], []);
    }
}

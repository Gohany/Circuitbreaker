<?php

namespace Gohany\Circuitbreaker\Store\Redis;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\CircuitHistory;
use Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface;
use Gohany\Circuitbreaker\Store\HistoryRecord;

final class RedisCircuitHistoryStore implements CircuitHistoryStoreInterface
{
    private RedisClientInterface $redis;
    private RedisKeyBuilder $keys;
    private int $bucketTtlSeconds;
    private int $countersTtlSeconds;

    public function __construct(
        RedisClientInterface $redis,
        RedisKeyBuilder $keys,
        int $bucketTtlSeconds = 900,
        int $countersTtlSeconds = 0
    ) {
        $this->redis = $redis;
        $this->keys = $keys;
        $this->bucketTtlSeconds = $bucketTtlSeconds;
        $this->countersTtlSeconds = $countersTtlSeconds;
    }

    public function getHistory(CircuitKey $key): CircuitHistory
    {
        $cKey = $this->keys->countersKey($key);
        $counters = $this->redis->hGetAll($cKey);

        $norm = [];
        foreach ($counters as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $norm[$k] = is_numeric($v) ? (int) $v : $v;
        }

        return new CircuitHistory($norm, []);
    }

    public function record(CircuitKey $key, HistoryRecord $record): void
    {
        $cKey = $this->keys->countersKey($key);
        $epochMinute = (int) floor(((int) $record->tsMs) / 60000);
        $bKey = $this->keys->bucketKey($key, $epochMinute);

        $signalsCsv = '';
        if (!empty($record->signals)) {
            $signalsCsv = implode(',', $record->signals);
        }

        $this->redis->eval(LuaScripts::RECORD_HISTORY, [$cKey, $bKey], [
            (string) (int) $record->tsMs,
            $record->success ? '1' : '0',
            (string) (int) $record->durationMs,
            (string) $signalsCsv,
            (string) $this->bucketTtlSeconds,
            (string) $this->countersTtlSeconds,
        ]);
    }
}

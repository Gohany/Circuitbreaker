<?php

declare(strict_types=1);

namespace tests\Util;

use Gohany\Circuitbreaker\Contracts\RedisClientInterface;

final class InMemoryRedisClient implements RedisClientInterface
{
    /** @var array<string,int> */
    private $kv = [];

    /** @var array<string,string> */
    private $strings = [];

    /** @var array<string,array<string,string>> */
    private $hashes = [];

    /** @var array<string,array<string,float>> key => [member => score] */
    private $zsets = [];

    /** @var array<string,int> key => expiry epoch ms */
    private $expiresAtMs = [];

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    private function isExpired(string $key): bool
    {
        if (!isset($this->expiresAtMs[$key])) {
            return false;
        }
        if ($this->expiresAtMs[$key] > $this->nowMs()) {
            return false;
        }
        unset($this->expiresAtMs[$key]);
        unset($this->strings[$key]);
        unset($this->hashes[$key]);
        unset($this->zsets[$key]);
        unset($this->kv[$key]);
        return true;
    }

    public function get(string $key): ?string
    {
        $this->isExpired($key);
        if (isset($this->strings[$key])) {
            return $this->strings[$key];
        }
        if (isset($this->kv[$key])) {
            return (string) $this->kv[$key];
        }
        return null;
    }

    /**
     * Minimal Redis `SET` supporting `NX` and `PX` options as used by bulkhead Lua.
     *
     * @param array<string,mixed> $options
     */
    public function set(string $key, string $value, array $options = []): bool
    {
        $this->isExpired($key);

        $nx = isset($options['nx']) ? (bool) $options['nx'] : false;
        $px = isset($options['px']) ? (int) $options['px'] : null;

        if ($nx && $this->exists($key)) {
            return false;
        }
        $this->strings[$key] = $value;
        unset($this->kv[$key]);

        if ($px !== null) {
            $this->expiresAtMs[$key] = $this->nowMs() + $px;
        } else {
            unset($this->expiresAtMs[$key]);
        }
        return true;
    }

    public function del(string $key): void
    {
        unset($this->kv[$key], $this->strings[$key], $this->hashes[$key], $this->zsets[$key], $this->expiresAtMs[$key]);
    }

    public function incr(string $key): int
    {
        $this->isExpired($key);
        $v = (int) ($this->kv[$key] ?? (int) ($this->strings[$key] ?? 0));
        $v++;
        $this->kv[$key] = $v;
        unset($this->strings[$key]);
        return $v;
    }

    public function decr(string $key): int
    {
        $this->isExpired($key);
        $v = (int) ($this->kv[$key] ?? (int) ($this->strings[$key] ?? 0));
        $v--;
        $this->kv[$key] = $v;
        unset($this->strings[$key]);
        return $v;
    }

    public function exists(string $key): bool
    {
        $this->isExpired($key);
        return array_key_exists($key, $this->kv)
            || array_key_exists($key, $this->strings)
            || array_key_exists($key, $this->hashes)
            || array_key_exists($key, $this->zsets);
    }

    public function hset(string $key, string $field, string $value): void
    {
        $this->isExpired($key);
        if (!isset($this->hashes[$key])) {
            $this->hashes[$key] = [];
        }
        $this->hashes[$key][$field] = $value;
    }

    public function hget(string $key, string $field): ?string
    {
        $this->isExpired($key);
        return $this->hashes[$key][$field] ?? null;
    }

    public function zadd(string $key, float $score, string $member, bool $nx = false): bool
    {
        $this->isExpired($key);
        if (!isset($this->zsets[$key])) {
            $this->zsets[$key] = [];
        }
        if ($nx && isset($this->zsets[$key][$member])) {
            return false;
        }
        $this->zsets[$key][$member] = $score;
        return true;
    }

    /**
     * @return list<string>|list<string|float>
     */
    public function zrange(string $key, int $start, int $stop, bool $withScores = false): array
    {
        $this->isExpired($key);
        $set = $this->zsets[$key] ?? [];
        asort($set, SORT_NUMERIC);
        $members = array_keys($set);

        $slice = array_slice($members, $start, $stop - $start + 1);
        if (!$withScores) {
            return array_values($slice);
        }

        $out = [];
        foreach ($slice as $m) {
            $out[] = $m;
            $out[] = (float) $set[$m];
        }
        return $out;
    }

    public function zrem(string $key, string $member): void
    {
        $this->isExpired($key);
        unset($this->zsets[$key][$member]);
    }

    public function eval(string $script, array $keys, array $args)
    {
        // This is NOT a Lua interpreter. It's a minimal simulation for the bulkhead Lua scripts.

        // RedisFairQueueBulkhead setup() script: 3 keys + (>=5) args
        if (count($keys) === 3 && count($args) >= 5) {
            // KEYS[1] cfgKey, KEYS[2] laneCapsKey, KEYS[3] laneWeightsKey
            // ARGV[1] globalMax, ARGV[2] softThreshold, ARGV[3] scanLimit, ARGV[4] pumpPerCall, ARGV[5] grantTtlMs
            // ARGV[6..] lane,cap,weight triples
            $cfgKey = $keys[0];
            $laneCapsKey = $keys[1];
            $laneWeightsKey = $keys[2];

            $this->hset($cfgKey, 'global_max', (string) $args[0]);
            $this->hset($cfgKey, 'soft_threshold', (string) $args[1]);
            $this->hset($cfgKey, 'scan_limit', (string) $args[2]);
            $this->hset($cfgKey, 'pump_per_call', (string) $args[3]);
            $this->hset($cfgKey, 'grant_ttl_ms', (string) $args[4]);

            for ($i = 5; $i + 2 < count($args); $i += 3) {
                $lane = (string) $args[$i];
                $cap = (string) $args[$i + 1];
                $weight = (string) $args[$i + 2];
                $this->hset($laneCapsKey, $lane, $cap);
                $this->hset($laneWeightsKey, $lane, $weight);
            }

            return 1;
        }

        // RedisFairQueueBulkhead wait() script: 7 keys + 9 args
        if (count($keys) === 7 && count($args) === 9) {
            return $this->evalFairQueueWait($keys, $args);
        }

        // RedisFairQueueBulkhead cleanup() script: 2 keys + 1 arg
        if (count($keys) === 2 && count($args) === 1) {
            // ZREM queueKey member; DEL grantKey
            $this->zrem($keys[0], (string) $args[0]);
            $this->del($keys[1]);
            return 1;
        }

        // RedisFairQueueBulkhead release() script: 2 keys + 0 args
        if (count($keys) === 2 && count($args) === 0) {
            $g = (int) ($this->get($keys[0]) ?? '0');
            $l = (int) ($this->get($keys[1]) ?? '0');
            if ($g > 0) {
                $this->decr($keys[0]);
            }
            if ($l > 0) {
                $this->decr($keys[1]);
            }
            $g2 = max($g - 1, 0);
            $l2 = max($l - 1, 0);
            // keep kv in sync
            $this->kv[$keys[0]] = $g2;
            $this->kv[$keys[1]] = $l2;
            return [1, $g2, $l2];
        }

        // Legacy minimal simulation for RedisPoolBulkhead acquire/release.
        if (count($args) >= 3) {
            $globalKey = $keys[0];
            $laneKey = $keys[1];
            $g = (int) ($this->kv[$globalKey] ?? 0);
            $l = (int) ($this->kv[$laneKey] ?? 0);
            $globalMax = (int) $args[0];
            $laneCap = (int) $args[1];
            $softThreshold = (int) $args[2];

            if ($g >= $globalMax) {
                return [0, $g, $l];
            }
            if ($g < $softThreshold) {
                $this->kv[$globalKey] = $g + 1;
                $this->kv[$laneKey] = $l + 1;
                return [1, $g + 1, $l + 1];
            }
            if ($l >= $laneCap) {
                return [0, $g, $l];
            }
            $this->kv[$globalKey] = $g + 1;
            $this->kv[$laneKey] = $l + 1;
            return [1, $g + 1, $l + 1];
        }

        // release
        $globalKey = $keys[0];
        $laneKey = $keys[1];
        $g = (int) ($this->kv[$globalKey] ?? 0);
        $l = (int) ($this->kv[$laneKey] ?? 0);
        $this->kv[$globalKey] = max(0, $g - 1);
        $this->kv[$laneKey] = max(0, $l - 1);
        return [1, $this->kv[$globalKey], $this->kv[$laneKey]];
    }

    /**
     * @param list<string> $keys
     * @param list<string> $args
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function evalFairQueueWait(array $keys, array $args): array
    {
        $globalKey = $keys[0];
        $queueKey = $keys[1];
        $cfgKey = $keys[2];
        $laneCapsKey = $keys[3];
        $laneWeightsKey = $keys[4];
        $myGrantKey = $keys[5];
        $myLaneKey = $keys[6];

        $myReqId = (string) $args[0];
        $myLane = (string) $args[1];
        $myExpireAtMs = (int) $args[2];
        $nowMs = (int) $args[3];
        $globalMax = (int) $args[4];
        $softThreshold = (int) $args[5];
        $scanLimit = (int) $args[6];
        $pumpPerCall = (int) $args[7];
        $grantTtlMs = (int) $args[8];

        // 1) If I have a grant, try to finalize it.
        $grant = $this->get($myGrantKey);
        if ($grant !== null) {
            $g = (int) ($this->get($globalKey) ?? '0');
            $l = (int) ($this->get($myLaneKey) ?? '0');
            $laneCap = (int) ($this->hget($laneCapsKey, $myLane) ?? '1');

            if ($g < $globalMax && ($g < $softThreshold || $l < $laneCap)) {
                $g2 = $this->incr($globalKey);
                $l2 = $this->incr($myLaneKey);
                $this->del($myGrantKey);
                return [1, $g2, $l2, 0];
            }
        }

        // 2) Enqueue (NX)
        $member = $myReqId . '|' . $myLane . '|' . (string) $myExpireAtMs;
        $this->zadd($queueKey, (float) $nowMs, $member, true);

        // 3) Pump
        $pumped = 0;
        $gNow = (int) ($this->get($globalKey) ?? '0');
        if ($gNow < $globalMax) {
            for ($p = 0; $p < $pumpPerCall; $p++) {
                $gNow = (int) ($this->get($globalKey) ?? '0');
                if ($gNow >= $globalMax) {
                    break;
                }

                $entries = $this->zrange($queueKey, 0, max(0, $scanLimit - 1), true);
                if (count($entries) === 0) {
                    break;
                }

                $bestMember = null;
                $bestReqId = null;
                $bestLane = null;
                $bestPriority = -1.0;

                for ($i = 0; $i < count($entries); $i += 2) {
                    $e = (string) $entries[$i];
                    $score = (float) $entries[$i + 1];

                    $parts = explode('|', $e, 3);
                    if (count($parts) !== 3) {
                        $this->zrem($queueKey, $e);
                        continue;
                    }
                    [$reqId, $lane, $expRaw] = $parts;
                    $exp = (int) $expRaw;
                    if ($exp > 0 && $exp < $nowMs) {
                        $this->zrem($queueKey, $e);
                        continue;
                    }

                    // lane key derivation matches Lua: base = globalKey minus ':global'
                    $base = substr($globalKey, 0, max(0, strlen($globalKey) - strlen(':global')));
                    $laneKey = $base . ':lane:' . $lane;
                    $lNow = (int) ($this->get($laneKey) ?? '0');
                    $laneCap = (int) ($this->hget($laneCapsKey, $lane) ?? '1');
                    $weight = (int) ($this->hget($laneWeightsKey, $lane) ?? '1');
                    if ($weight < 1) {
                        $weight = 1;
                    }

                    if ($gNow < $softThreshold || $lNow < $laneCap) {
                        $age = $nowMs - (int) $score;
                        if ($age < 0) {
                            $age = 0;
                        }
                        $priority = $age * $weight;
                        if ($priority > $bestPriority) {
                            $bestPriority = (float) $priority;
                            $bestMember = $e;
                            $bestReqId = $reqId;
                            $bestLane = $lane;
                        }
                    }
                }

                if ($bestMember === null || $bestReqId === null || $bestLane === null) {
                    break;
                }

                $this->zrem($queueKey, $bestMember);

                // grantKey prefix = myGrantKey without myReqId suffix
                $prefix = substr($myGrantKey, 0, strlen($myGrantKey) - strlen($myReqId));
                $grantKey = $prefix . $bestReqId;

                $ok = $this->set($grantKey, $bestLane, ['nx' => true, 'px' => $grantTtlMs]);
                if ($ok) {
                    $pumped++;
                }
            }
        }

        // 4) Check grant again for myself
        $grant = $this->get($myGrantKey);
        if ($grant !== null) {
            $g = (int) ($this->get($globalKey) ?? '0');
            $l = (int) ($this->get($myLaneKey) ?? '0');
            $laneCap = (int) ($this->hget($laneCapsKey, $myLane) ?? '1');
            if ($g < $globalMax && ($g < $softThreshold || $l < $laneCap)) {
                $g2 = $this->incr($globalKey);
                $l2 = $this->incr($myLaneKey);
                $this->del($myGrantKey);
                return [1, $g2, $l2, $pumped];
            }
        }

        $gFinal = (int) ($this->get($globalKey) ?? '0');
        $lFinal = (int) ($this->get($myLaneKey) ?? '0');
        return [0, $gFinal, $lFinal, $pumped];
    }
}

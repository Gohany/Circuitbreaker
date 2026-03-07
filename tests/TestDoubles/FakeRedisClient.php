<?php

declare(strict_types=1);

namespace tests\TestDoubles;

use Gohany\Circuitbreaker\Store\Redis\LuaScripts;
use Gohany\Circuitbreaker\Store\Redis\RedisClientInterface;

final class FakeRedisClient implements RedisClientInterface
{
    /** @var array<string,string> */
    private array $strings = [];
    /** @var array<string,array<string,string>> */
    private array $hashes = [];
    /** @var array<string,int> */
    private array $expiresAtMs = [];
    private int $nowMs = 0;

    public function setNowMs(int $nowMs): void
    {
        $this->nowMs = $nowMs;
        $this->expireSweep();
    }

    private function expireSweep(): void
    {
        foreach ($this->expiresAtMs as $k => $exp) {
            if ($this->nowMs >= $exp) {
                unset($this->expiresAtMs[$k], $this->strings[$k], $this->hashes[$k]);
            }
        }
    }

    public function eval(string $script, array $keys = [], array $args = [])
    {
        $this->expireSweep();

        if ($script === LuaScripts::CAS_UPDATE_STATE) {
            return $this->luaCasUpdateState($keys, $args);
        }
        if ($script === LuaScripts::ACQUIRE_PROBE) {
            return $this->luaAcquireProbe($keys, $args);
        }
        if ($script === LuaScripts::RELEASE_PROBE) {
            return $this->luaReleaseProbe($keys, $args);
        }
        if ($script === LuaScripts::RECORD_HISTORY) {
            return $this->luaRecordHistory($keys, $args);
        }

        throw new \RuntimeException('Unknown script passed to FakeRedisClient::eval');
    }

    public function get($key)
    {
        $this->expireSweep();
        return $this->strings[$key] ?? false;
    }

    public function set($key, $value, array $options = [])
    {
        $this->expireSweep();

        if (isset($options['nx']) && $options['nx'] === true) {
            if (isset($this->strings[$key]) || isset($this->hashes[$key])) {
                return false;
            }
        }

        $this->strings[$key] = (string) $value;

        if (isset($options['px'])) {
            $this->expiresAtMs[$key] = $this->nowMs + (int) $options['px'];
        }

        return true;
    }

    public function del($key)
    {
        $this->expireSweep();
        unset($this->strings[$key], $this->hashes[$key], $this->expiresAtMs[$key]);
        return 1;
    }

    public function hGetAll($key)
    {
        $this->expireSweep();
        return $this->hashes[$key] ?? [];
    }

    public function hMSet($key, array $fields)
    {
        $this->expireSweep();
        if (!isset($this->hashes[$key])) {
            $this->hashes[$key] = [];
        }
        foreach ($fields as $k => $v) {
            $this->hashes[$key][(string) $k] = (string) $v;
        }
        return true;
    }

    public function hIncrBy($key, $field, $by)
    {
        $this->expireSweep();
        if (!isset($this->hashes[$key])) {
            $this->hashes[$key] = [];
        }
        $cur = isset($this->hashes[$key][$field]) ? (int) $this->hashes[$key][$field] : 0;
        $cur += (int) $by;
        $this->hashes[$key][$field] = (string) $cur;
        return $cur;
    }

    public function expire($key, $ttlSeconds)
    {
        $this->expireSweep();
        $this->expiresAtMs[$key] = $this->nowMs + ((int) $ttlSeconds * 1000);
        return true;
    }

    public function pExpire($key, $ttlMs)
    {
        $this->expireSweep();
        $this->expiresAtMs[$key] = $this->nowMs + (int) $ttlMs;
        return true;
    }

    public function exists($key)
    {
        $this->expireSweep();
        return isset($this->strings[$key]) || isset($this->hashes[$key]);
    }

    public function scan(&$iterator, $pattern, $count = 100)
    {
        $this->expireSweep();
        $allKeys = array_unique(array_merge(array_keys($this->strings), array_keys($this->hashes)));

        if ($iterator === null) {
            $iterator = 0;
        }

        $matched = [];
        foreach ($allKeys as $k) {
            if ($this->matchesPattern($k, (string) $pattern)) {
                $matched[] = $k;
            }
        }

        $iterator = 0;
        return $matched;
    }

    private function matchesPattern(string $key, string $pattern): bool
    {
        $re = '/^' . str_replace(['\\*'], ['.*'], preg_quote($pattern, '/')) . '$/';
        return (bool) preg_match($re, $key);
    }

    private function luaCasUpdateState(array $keys, array $args)
    {
        $key = $keys[0];
        $expected = (string) $args[0];

        $curv = $this->hashes[$key]['version'] ?? '0';
        if ((string) $curv !== $expected) {
            return 0;
        }

        $newv = ((int) $curv) + 1;

        $this->hMSet($key, [
            'version' => (string) $newv,
            'mode' => (string) $args[1],
            'open_until_ms' => (string) $args[2],
            'half_open_in_flight' => (string) $args[3],
            'meta_json' => (string) $args[4],
        ]);

        $pexp = (int) $args[5];
        if ($pexp > 0) {
            $this->pExpire($key, $pexp);
        }

        return 1;
    }

    private function luaAcquireProbe(array $keys, array $args)
    {
        $key = $keys[0];
        $now = (int) $args[0];
        $maxInFlight = (int) $args[1];
        $allowOpenExpiredToHalfOpen = ((int) $args[3]) === 1;
        $pexp = (int) $args[4];

        $mode = $this->hashes[$key]['mode'] ?? 'closed';
        $openUntilStr = $this->hashes[$key]['open_until_ms'] ?? '';
        $inFlight = isset($this->hashes[$key]['half_open_in_flight']) ? (int) $this->hashes[$key]['half_open_in_flight'] : 0;

        if ($mode === 'open') {
            $ou = $openUntilStr === '' ? null : (int) $openUntilStr;
            if ($allowOpenExpiredToHalfOpen && $ou !== null && $now >= $ou) {
                $mode = 'half_open';
                $openUntilStr = '';
                $this->hMSet($key, ['mode' => $mode, 'open_until_ms' => '']);
            } else {
                $retryAfter = 0;
                if ($ou !== null && $now < $ou) {
                    $retryAfter = $ou - $now;
                }
                return [0, $mode, (string) $openUntilStr, $inFlight, $retryAfter];
            }
        }

        if ($mode === 'half_open') {
            if ($inFlight >= $maxInFlight) {
                return [0, $mode, (string) $openUntilStr, $inFlight, 250];
            }
            $inFlight++;
            $this->hMSet($key, ['half_open_in_flight' => (string) $inFlight]);
            if ($pexp > 0) {
                $this->pExpire($key, $pexp);
            }
            return [1, $mode, (string) $openUntilStr, $inFlight, 0];
        }

        return [1, $mode, (string) $openUntilStr, $inFlight, 0];
    }

    private function luaReleaseProbe(array $keys, array $args)
    {
        $key = $keys[0];
        $mode = $this->hashes[$key]['mode'] ?? 'closed';
        $inFlight = isset($this->hashes[$key]['half_open_in_flight']) ? (int) $this->hashes[$key]['half_open_in_flight'] : 0;

        if ($mode !== 'half_open') {
            return $inFlight;
        }

        if ($inFlight > 0) {
            $inFlight--;
            $this->hMSet($key, ['half_open_in_flight' => (string) $inFlight]);
        }

        return $inFlight;
    }

    private function luaRecordHistory(array $keys, array $args)
    {
        $countersKey = $keys[0];
        $bucketKey = $keys[1];

        $now = (int) $args[0];
        $success = ((int) $args[1]) === 1;
        $duration = (int) $args[2];
        $signalsCsv = (string) $args[3];
        $bucketTtl = (int) $args[4];
        $countersTtl = (int) $args[5];

        if ($success) {
            $this->hIncrBy($countersKey, 'total_success', 1);
            $this->hMSet($countersKey, ['last_success_ms' => (string) $now, 'consecutive_failures' => '0']);
        } else {
            $this->hIncrBy($countersKey, 'total_failure', 1);
            $this->hMSet($countersKey, ['last_failure_ms' => (string) $now]);
            $this->hIncrBy($countersKey, 'consecutive_failures', 1);
        }

        $this->hIncrBy($countersKey, 'total_duration_ms', $duration);

        if ($success) {
            $this->hIncrBy($bucketKey, 'success', 1);
        } else {
            $this->hIncrBy($bucketKey, 'failure', 1);
        }

        if ($signalsCsv !== '') {
            foreach (explode(',', $signalsCsv) as $sig) {
                if ($sig === '') {
                    continue;
                }
                $this->hIncrBy($bucketKey, $sig, 1);
            }
        }

        if ($bucketTtl > 0) {
            $this->expire($bucketKey, $bucketTtl);
        }
        if ($countersTtl > 0) {
            $this->expire($countersKey, $countersTtl);
        }

        $cf = isset($this->hashes[$countersKey]['consecutive_failures']) ? (int) $this->hashes[$countersKey]['consecutive_failures'] : 0;
        $ts = isset($this->hashes[$countersKey]['total_success']) ? (int) $this->hashes[$countersKey]['total_success'] : 0;
        $tf = isset($this->hashes[$countersKey]['total_failure']) ? (int) $this->hashes[$countersKey]['total_failure'] : 0;

        return [$cf, $ts, $tf];
    }
}

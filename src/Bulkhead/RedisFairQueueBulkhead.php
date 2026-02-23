<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Bulkhead;

use Gohany\Circuitbreaker\Contracts\BulkheadInterface;
use Gohany\Circuitbreaker\Contracts\BulkheadPermitInterface;
use Gohany\Circuitbreaker\Contracts\RedisClientInterface;
use Gohany\Circuitbreaker\Exception\BulkheadRejectedException;
use Gohany\Circuitbreaker\Observability\EmitterInterface;
use Gohany\Circuitbreaker\Observability\NullEmitter;

/**
 * Distributed wait-queue bulkhead backed by Redis.
 *
 * Goals:
 *  - global concurrency cap shared across nodes
 *  - per-lane caps (fixed/percent/weighted) with optional soft borrowing
 *  - fairness across nodes via a shared queue (best-effort)
 *  - avoids head-of-line blocking by scanning a small window and granting the earliest *admissible* request
 *
 * How it works:
 *  - Each acquire() call enqueues a request (reqId|lane|expireAtMs) into a shared ZSET.
 *  - Callers poll a Lua script that:
 *      1) finalizes their grant (if they have one) by incrementing counters
 *      2) "pumps" the queue by granting up to N admissible entries (writes short-lived grant keys)
 *  - Grant keys are short-lived and DO NOT increment counters until the owner finalizes.
 */
final class RedisFairQueueBulkhead implements BulkheadInterface
{
    /** @var RedisClientInterface */
    private $redis;
    /** @var PoolPolicy */
    private $policy;
    /** @var string */
    private $keyPrefix;
    /** @var EmitterInterface */
    private $emitter;

    /** @var int */
    private $scanLimit;
    /** @var int */
    private $pumpPerCall;
    /** @var int */
    private $grantTtlMs;
    /** @var int */
    private $pollIntervalMs;

    /** @var string */
    private $setupLua;
    /** @var string */
    private $waitLua;
    /** @var string */
    private $cleanupLua;
    /** @var string */
    private $releaseLua;

    /** @var array<string,int> */
    private $laneCaps;

    /** @var array<string,int> */
    private $laneWeights;

    /**
     * @param array{scan_limit?:int,pump_per_call?:int,grant_ttl_ms?:int,poll_interval_ms?:int} $queueOptions
     */
    public function __construct(
        RedisClientInterface $redis,
        PoolPolicy $policy,
        string $keyPrefix = 'cb',
        ?EmitterInterface $emitter = null,
        array $queueOptions = []
    ) {
        $this->redis = $redis;
        $this->policy = $policy;
        $this->keyPrefix = $keyPrefix;
        $this->emitter = $emitter ?: new NullEmitter();

        $this->scanLimit = (int) ($queueOptions['scan_limit'] ?? 64);
        $this->pumpPerCall = (int) ($queueOptions['pump_per_call'] ?? 3);
        $this->grantTtlMs = (int) ($queueOptions['grant_ttl_ms'] ?? 250);
        $this->pollIntervalMs = (int) ($queueOptions['poll_interval_ms'] ?? 10);

        $this->laneCaps = $this->computeAllLaneCaps();
        $this->laneWeights = $this->computeAllLaneWeights();

        $this->setupLua = <<<'LUA'
-- KEYS[1] cfgKey
-- KEYS[2] laneCapsKey
-- KEYS[3] laneWeightsKey
-- ARGV[1] globalMax
-- ARGV[2] softThresholdInFlight
-- ARGV[3] scanLimit
-- ARGV[4] pumpPerCall
-- ARGV[5] grantTtlMs
-- ARGV[6..] lane,cap,weight triples

redis.call('HSET', KEYS[1],
  'global_max', ARGV[1],
  'soft_threshold', ARGV[2],
  'scan_limit', ARGV[3],
  'pump_per_call', ARGV[4],
  'grant_ttl_ms', ARGV[5]
)

local i = 6
while i <= #ARGV do
  local lane = ARGV[i]
  local cap = ARGV[i+1]
  local weight = ARGV[i+2]
  redis.call('HSET', KEYS[2], lane, cap)
  redis.call('HSET', KEYS[3], lane, weight)
  i = i + 3
end

return 1
LUA;

        $this->waitLua = <<<'LUA'
-- KEYS[1] globalKey
-- KEYS[2] queueKey
-- KEYS[3] cfgKey
-- KEYS[4] laneCapsKey
-- KEYS[5] laneWeightsKey
-- KEYS[6] myGrantKey
-- KEYS[7] myLaneKey
-- ARGV[1] myReqId
-- ARGV[2] myLane
-- ARGV[3] myExpireAtMs
-- ARGV[4] nowMs
-- ARGV[5] globalMax
-- ARGV[6] softThresholdInFlight
-- ARGV[7] scanLimit
-- ARGV[8] pumpPerCall
-- ARGV[9] grantTtlMs

local nowMs = tonumber(ARGV[4])
local globalMax = tonumber(ARGV[5])
local softThreshold = tonumber(ARGV[6])
local scanLimit = tonumber(ARGV[7])
local pumpPerCall = tonumber(ARGV[8])
local grantTtlMs = tonumber(ARGV[9])

-- 1) If I have a grant, finalize it by incrementing counters if admissible
local grant = redis.call('GET', KEYS[6])
if grant then
  local lane = ARGV[2]
  local g = tonumber(redis.call('GET', KEYS[1]) or '0')
  local l = tonumber(redis.call('GET', KEYS[7]) or '0')
  local laneCap = tonumber(redis.call('HGET', KEYS[4], lane) or '1')

  if g < globalMax then
    if g < softThreshold or l < laneCap then
      redis.call('INCR', KEYS[1])
      redis.call('INCR', KEYS[7])
      redis.call('DEL', KEYS[6])
      return {1, g+1, l+1, 0}
    end
  end
  -- Not admissible yet; keep waiting.
end

-- 2) Ensure I'm enqueued (idempotent)
local member = ARGV[1] .. '|' .. ARGV[2] .. '|' .. ARGV[3]
redis.call('ZADD', KEYS[2], 'NX', nowMs, member)

-- 3) Pump queue: pick best admissible entry from a small window.
-- Weighted selection: priority = age_ms * lane_weight
-- This favors older requests while still biasing toward higher-weight lanes under contention.
local pumped = 0
local gNow = tonumber(redis.call('GET', KEYS[1]) or '0')
if gNow < globalMax then
  for p = 1, pumpPerCall do
    gNow = tonumber(redis.call('GET', KEYS[1]) or '0')
    if gNow >= globalMax then break end

    local entries = redis.call('ZRANGE', KEYS[2], 0, scanLimit-1, 'WITHSCORES')
    if #entries == 0 then break end

    local bestMember = nil
    local bestReqId = nil
    local bestLane = nil
    local bestPriority = -1

    local idx = 1
    while idx <= #entries do
      local e = entries[idx]
      local score = tonumber(entries[idx+1] or '0')
      idx = idx + 2

      local s1 = string.find(e, '|')
      if not s1 then
        redis.call('ZREM', KEYS[2], e)
      else
        local s2 = string.find(e, '|', s1+1)
        if not s2 then
          redis.call('ZREM', KEYS[2], e)
        else
          local reqId = string.sub(e, 1, s1-1)
          local lane = string.sub(e, s1+1, s2-1)
          local exp = tonumber(string.sub(e, s2+1))

          if exp and exp < nowMs then
            redis.call('ZREM', KEYS[2], e)
          else
            local base = string.sub(KEYS[1], 1, string.len(KEYS[1]) - string.len(':global'))
            local laneKey = base .. ':lane:' .. lane
            local lNow = tonumber(redis.call('GET', laneKey) or '0')
            local laneCap = tonumber(redis.call('HGET', KEYS[4], lane) or '1')
            local weight = tonumber(redis.call('HGET', KEYS[5], lane) or '1')

            if gNow < softThreshold or lNow < laneCap then
              local age = nowMs - (score or nowMs)
              if age < 0 then age = 0 end
              -- Stronger bias toward higher-weight lanes while still allowing age to dominate over time.
              local priority = age * weight * weight
              if priority > bestPriority then
                bestPriority = priority
                bestMember = e
                bestReqId = reqId
                bestLane = lane
              end
            end
          end
        end
      end
    end

    if not bestMember then break end

    redis.call('ZREM', KEYS[2], bestMember)
    local grantKey = string.sub(KEYS[6], 1, string.len(KEYS[6]) - string.len(ARGV[1])) .. bestReqId
    local ok = redis.call('SET', grantKey, bestLane, 'PX', grantTtlMs, 'NX')
    if ok then
      pumped = pumped + 1
    end
  end
end

-- 4) After pumping, check grant again and finalize if possible
grant = redis.call('GET', KEYS[6])
if grant then
  local lane = ARGV[2]
  local g = tonumber(redis.call('GET', KEYS[1]) or '0')
  local l = tonumber(redis.call('GET', KEYS[7]) or '0')
  local laneCap = tonumber(redis.call('HGET', KEYS[4], lane) or '1')
  if g < globalMax and (g < softThreshold or l < laneCap) then
    redis.call('INCR', KEYS[1])
    redis.call('INCR', KEYS[7])
    redis.call('DEL', KEYS[6])
    return {1, g+1, l+1, pumped}
  end
end

return {0, tonumber(redis.call('GET', KEYS[1]) or '0'), tonumber(redis.call('GET', KEYS[7]) or '0'), pumped}
LUA;

        $this->cleanupLua = <<<'LUA'
-- KEYS[1] queueKey
-- KEYS[2] myGrantKey
-- ARGV[1] member

redis.call('ZREM', KEYS[1], ARGV[1])
redis.call('DEL', KEYS[2])
return 1
LUA;

        $this->releaseLua = <<<'LUA'
-- KEYS[1] globalKey
-- KEYS[2] laneKey

local g = tonumber(redis.call('GET', KEYS[1]) or '0')
local l = tonumber(redis.call('GET', KEYS[2]) or '0')

if g > 0 then redis.call('DECR', KEYS[1]) end
if l > 0 then redis.call('DECR', KEYS[2]) end

return {1, math.max(g-1,0), math.max(l-1,0)}
LUA;

        $this->setupPoolConfigInRedis();
    }

    public function acquire(string $lane, ?float $timeoutSeconds = null): BulkheadPermitInterface
    {
        $poolId = $this->policy->getPoolId();
        $globalMax = $this->policy->getGlobalMax();
        $laneCap = $this->laneCaps[$lane] ?? max(1, (int) floor($globalMax * 0.10));
        $softThreshold = (int) floor($globalMax * $this->policy->getSoftBorrowUtilizationThreshold());

        $nowMs = (int) (microtime(true) * 1000);

        $timeoutMs = $timeoutSeconds === null
            ? 0
            : (int) floor($timeoutSeconds * 1000);
        $deadlineMs = $timeoutMs > 0 ? ($nowMs + $timeoutMs) : $nowMs;

        $reqId = bin2hex(random_bytes(8));
        $permitId = $reqId;

        $globalKey = $this->keyPrefix . ':bulkhead:pool:' . $poolId . ':global';
        $queueKey = $this->keyPrefix . ':bulkhead:pool:' . $poolId . ':queue';
        $cfgKey = $this->keyPrefix . ':bulkhead:pool:' . $poolId . ':cfg';
        $laneCapsKey = $this->keyPrefix . ':bulkhead:pool:' . $poolId . ':lane_caps';
        $laneWeightsKey = $this->keyPrefix . ':bulkhead:pool:' . $poolId . ':lane_weights';
        $grantKey = $this->keyPrefix . ':bulkhead:pool:' . $poolId . ':grant:' . $reqId;
        $laneKey = $this->keyPrefix . ':bulkhead:pool:' . $poolId . ':lane:' . $lane;

        $member = $reqId . '|' . $lane . '|' . (string) $deadlineMs;

        // Fast-path: if timeoutSeconds is null, behave like fast-fail.
        if ($timeoutSeconds === null) {
            $deadlineMs = $nowMs;
        }

        while (true) {
            $nowMs = (int) (microtime(true) * 1000);
            // Treat timeoutSeconds=0 as an immediate timeout (no infinite wait).
            if ($timeoutSeconds !== null && $nowMs > $deadlineMs) {
                $this->redis->eval($this->cleanupLua, [$queueKey, $grantKey], [$member]);
                $this->emitter->emit('bulkhead.acquire_rejected', [
                    'bulkhead_id' => $poolId,
                    'lane' => $lane,
                    'reason' => 'timeout',
                    'global_max' => $globalMax,
                    'lane_cap' => $laneCap,
                ]);
                throw new BulkheadRejectedException($poolId, $lane);
            }

            $res = $this->redis->eval($this->waitLua, [
                $globalKey,
                $queueKey,
                $cfgKey,
                $laneCapsKey,
                $laneWeightsKey,
                $grantKey,
                $laneKey,
            ], [
                $reqId,
                $lane,
                (string) $deadlineMs,
                (string) $nowMs,
                (string) $globalMax,
                (string) $softThreshold,
                (string) $this->scanLimit,
                (string) $this->pumpPerCall,
                (string) $this->grantTtlMs,
            ]);

            $allowed = is_array($res) && isset($res[0]) ? (int) $res[0] : 0;
            $g = is_array($res) && isset($res[1]) ? (int) $res[1] : -1;
            $l = is_array($res) && isset($res[2]) ? (int) $res[2] : -1;
            // Older/stubbed Redis clients (e.g. in-memory test doubles) may not return the pumped count.
            $pumped = is_array($res) && isset($res[3]) ? (int) $res[3] : 0;

            if ($allowed === 1) {
                $this->emitter->emit('bulkhead.acquire_ok', [
                    'bulkhead_id' => $poolId,
                    'lane' => $lane,
                    'permit_id' => $permitId,
                    'global_in_flight' => $g,
                    'lane_in_flight' => $l,
                    'global_max' => $globalMax,
                    'lane_cap' => $laneCap,
                    'soft_threshold' => $softThreshold,
                    'queue_pumped' => $pumped,
                ]);

                return new BulkheadPermit($permitId, $lane, function (string $laneName, string $pid) use ($poolId, $globalKey, $laneKey): void {
                    $res2 = $this->redis->eval($this->releaseLua, [$globalKey, $laneKey], []);
                    $g2 = is_array($res2) ? (int) $res2[1] : -1;
                    $l2 = is_array($res2) ? (int) $res2[2] : -1;
                    $this->emitter->emit('bulkhead.release', [
                        'bulkhead_id' => $poolId,
                        'lane' => $laneName,
                        'permit_id' => $pid,
                        'global_in_flight' => $g2,
                        'lane_in_flight' => $l2,
                    ]);
                });
            }

            if ($timeoutSeconds === null) {
                $this->redis->eval($this->cleanupLua, [$queueKey, $grantKey], [$member]);
                $this->emitter->emit('bulkhead.acquire_rejected', [
                    'bulkhead_id' => $poolId,
                    'lane' => $lane,
                    'reason' => 'no_wait',
                    'global_max' => $globalMax,
                    'lane_cap' => $laneCap,
                ]);
                throw new BulkheadRejectedException($poolId, $lane);
            }

            usleep(max(1, $this->pollIntervalMs) * 1000);
        }
    }

    public function run(string $lane, callable $fn, ?float $timeoutSeconds = null)
    {
        $permit = $this->acquire($lane, $timeoutSeconds);
        try {
            return $fn();
        } finally {
            $permit->release();
        }
    }

    /**
     * Computes lane weights used by the weighted pump / fair-queue scheduler.
     *
     * @return array<string,int> laneName => weight (>= 1)
     */
    private function computeAllLaneWeights(): array
    {
        // Try to discover lane definitions from whatever shape this class/config uses.
        $laneDefs = null;

        // Prefer instance properties when present (avoid uninitialized typed props via isset()).
        if (isset($this->laneConfigs) && is_array($this->laneConfigs)) {
            $laneDefs = $this->laneConfigs; // [laneName => cfg]
        } elseif (isset($this->lanes) && is_array($this->lanes)) {
            $laneDefs = $this->lanes; // [laneName => cfg] or list of lane names
        } elseif (isset($this->config) && is_object($this->config)) {
            if (method_exists($this->config, 'getLaneConfigs')) {
                $laneDefs = $this->config->getLaneConfigs();
            } elseif (method_exists($this->config, 'getLanes')) {
                $laneDefs = $this->config->getLanes();
            }
        }

        if (!is_array($laneDefs) || $laneDefs === []) {
            // Fall back to the policy lanes (the canonical source in this implementation).
            // This is required for weighted mode where lane weights are defined via `LanePolicy::weight()`.
            $lanes = $this->policy->getLanes();
            if (is_array($lanes) && $lanes !== []) {
                $weights = [];
                foreach ($lanes as $laneName => $lp) {
                    if (!is_string($laneName) || $laneName == '') {
                        continue;
                    }
                    $w = 1;
                    if (is_object($lp) && method_exists($lp, 'getWeight')) {
                        $w = (int) ($lp->getWeight() ?? 1);
                    }
                    if ($w < 1) {
                        $w = 1;
                    }
                    $weights[$laneName] = $w;
                }
                if ($weights !== []) {
                    return $weights;
                }
            }

            // Safe fallback: at least one lane with weight 1
            return ['default' => 1];
        }

        $weights = [];

        foreach ($laneDefs as $laneKey => $laneCfg) {
            // Support both associative (laneName => cfg) and sequential ([laneName, laneName2]) shapes.
            if (is_int($laneKey)) {
                $laneName = (string) $laneCfg;
                $weight = 1;
            } else {
                $laneName = (string) $laneKey;
                $weight = 1;

                if (is_array($laneCfg) && array_key_exists('weight', $laneCfg)) {
                    $weight = (int) $laneCfg['weight'];
                } elseif (is_object($laneCfg)) {
                    if (method_exists($laneCfg, 'getWeight')) {
                        $weight = (int) $laneCfg->getWeight();
                    } elseif (property_exists($laneCfg, 'weight')) {
                        /** @phpstan-ignore-next-line */
                        $weight = (int) $laneCfg->weight;
                    }
                }
            }

            if ($weight < 1) {
                $weight = 1;
            }

            // Ignore empty lane names defensively
            if ($laneName !== '') {
                $weights[$laneName] = $weight;
            }
        }

        return $weights !== [] ? $weights : ['default' => 1];
    }

    /**
     * @return array<string,int>
     */
    private function computeAllLaneCaps(): array
    {
        $globalMax = $this->policy->getGlobalMax();
        $lanes = $this->policy->getLanes();
        $mode = $this->policy->getMode();

        $caps = [];
        if ($mode === PoolPolicy::MODE_WEIGHTED) {
            $sum = 0;
            foreach ($lanes as $policy) {
                $w = $policy->getWeight();
                $sum += ($w === null ? 1 : $w);
            }
            if ($sum <= 0) {
                $sum = 1;
            }
            foreach ($lanes as $lane => $lp) {
                $w = $lp->getWeight();
                $w = $w === null ? 1 : $w;
                $caps[$lane] = max(1, (int) floor($globalMax * ($w / $sum)));
            }
            return $caps;
        }

        foreach ($lanes as $lane => $lp) {
            if ($mode === PoolPolicy::MODE_FIXED) {
                $cap = $lp->getMaxConcurrent();
                $caps[$lane] = max(1, (int) ($cap ?: 1));
            } elseif ($mode === PoolPolicy::MODE_PERCENT) {
                $pct = $lp->getPercent();
                $pct = $pct === null ? 0.10 : $pct;
                $caps[$lane] = max(1, (int) floor($globalMax * $pct));
            }
        }

        return $caps;
    }

    private function setupPoolConfigInRedis(): void
    {
        $poolId = $this->policy->getPoolId();
        $globalMax = $this->policy->getGlobalMax();
        $softThreshold = (int) floor($globalMax * $this->policy->getSoftBorrowUtilizationThreshold());

        $cfgKey = $this->keyPrefix . ':bulkhead:pool:' . $poolId . ':cfg';
        $laneCapsKey = $this->keyPrefix . ':bulkhead:pool:' . $poolId . ':lane_caps';
        $laneWeightsKey = $this->keyPrefix . ':bulkhead:pool:' . $poolId . ':lane_weights';

        $argv = [
            (string) $globalMax,
            (string) $softThreshold,
            (string) $this->scanLimit,
            (string) $this->pumpPerCall,
            (string) $this->grantTtlMs,
        ];
        foreach ($this->laneCaps as $lane => $cap) {
            $argv[] = (string) $lane;
            $argv[] = (string) $cap;
            $argv[] = (string) ($this->laneWeights[$lane] ?? 1);
        }

        $this->redis->eval($this->setupLua, [$cfgKey, $laneCapsKey, $laneWeightsKey], $argv);
    }
}

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
 * Distributed bulkhead backed by Redis.
 *
 * Supports three lane-cap modes:
 *  - fixed: per-lane hard cap (LanePolicy::fixed)
 *  - percent: per-lane cap = floor(globalMax * percent)
 *  - weighted: per-lane cap = floor(globalMax * weight / sumWeights)
 *
 * Optional "soft borrowing": if global utilization is below a threshold, lane caps
 * are ignored (only the global cap is enforced). When utilization rises above the threshold,
 * lane caps kick in to prefer high-priority lanes.
 */
final class RedisPoolBulkhead implements BulkheadInterface
{
    /** @var RedisClientInterface */
    private $redis;
    /** @var PoolPolicy */
    private $policy;
    /** @var string */
    private $keyPrefix;
    /** @var EmitterInterface */
    private $emitter;

    /** @var string */
    private $acquireLua;
    /** @var string */
    private $releaseLua;

    public function __construct(
        RedisClientInterface $redis,
        PoolPolicy $policy,
        string $keyPrefix = 'cb',
        ?EmitterInterface $emitter = null
    ) {
        $this->redis = $redis;
        $this->policy = $policy;
        $this->keyPrefix = $keyPrefix;
        $this->emitter = $emitter ?: new NullEmitter();

        $this->acquireLua = <<<'LUA'
-- KEYS[1] globalKey
-- KEYS[2] laneKey
-- ARGV[1] globalMax
-- ARGV[2] laneCap
-- ARGV[3] softThresholdInFlight
-- ARGV[4] nowMs (unused, reserved for future)

local g = tonumber(redis.call('GET', KEYS[1]) or '0')
local l = tonumber(redis.call('GET', KEYS[2]) or '0')
local globalMax = tonumber(ARGV[1])
local laneCap = tonumber(ARGV[2])
local softThreshold = tonumber(ARGV[3])

if g >= globalMax then
  return {0, g, l}
end

-- Soft borrowing: below threshold, ignore lane cap
if g < softThreshold then
  redis.call('INCR', KEYS[1])
  redis.call('INCR', KEYS[2])
  return {1, g+1, l+1}
end

if l >= laneCap then
  return {0, g, l}
end

redis.call('INCR', KEYS[1])
redis.call('INCR', KEYS[2])
return {1, g+1, l+1}
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
    }

    public function acquire(string $lane, ?float $timeoutSeconds = null): BulkheadPermitInterface
    {
        // timeoutSeconds currently unused: we implement fast-fail admission control.
        // A wait-queue bulkhead can be layered later.

        $poolId = $this->policy->getPoolId();
        $globalMax = $this->policy->getGlobalMax();

        $laneCap = $this->computeLaneCap($lane);
        $softThreshold = (int) floor($globalMax * $this->policy->getSoftBorrowUtilizationThreshold());

        $globalKey = $this->keyPrefix . ':bulkhead:pool:' . $poolId . ':global';
        $laneKey = $this->keyPrefix . ':bulkhead:pool:' . $poolId . ':lane:' . $lane;

        $result = $this->redis->eval($this->acquireLua, [$globalKey, $laneKey], [
            (string) $globalMax,
            (string) $laneCap,
            (string) $softThreshold,
            (string) ((int) (microtime(true) * 1000)),
        ]);

        // result = [allowed(0/1), globalInFlight, laneInFlight]
        $allowed = is_array($result) ? (int) $result[0] : 0;
        $g = is_array($result) ? (int) $result[1] : -1;
        $l = is_array($result) ? (int) $result[2] : -1;

        if ($allowed !== 1) {
            $this->emitter->emit('bulkhead.acquire_rejected', [
                'bulkhead_id' => $poolId,
                'lane' => $lane,
                'global_in_flight' => $g,
                'lane_in_flight' => $l,
                'global_max' => $globalMax,
                'lane_cap' => $laneCap,
                'soft_threshold' => $softThreshold,
            ]);
            throw new BulkheadRejectedException($poolId, $lane);
        }

        $permitId = bin2hex(random_bytes(8));

        $this->emitter->emit('bulkhead.acquire_ok', [
            'bulkhead_id' => $poolId,
            'lane' => $lane,
            'permit_id' => $permitId,
            'global_in_flight' => $g,
            'lane_in_flight' => $l,
            'global_max' => $globalMax,
            'lane_cap' => $laneCap,
            'soft_threshold' => $softThreshold,
        ]);

        return new BulkheadPermit(
            $permitId,
            $lane,
            function (string $laneName, string $pid) use ($poolId, $globalKey, $laneKey): void {
                $res = $this->redis->eval($this->releaseLua, [$globalKey, $laneKey], []);
                $g2 = is_array($res) ? (int) $res[1] : -1;
                $l2 = is_array($res) ? (int) $res[2] : -1;

                $this->emitter->emit('bulkhead.release', [
                    'bulkhead_id' => $poolId,
                    'lane' => $laneName,
                    'permit_id' => $pid,
                    'global_in_flight' => $g2,
                    'lane_in_flight' => $l2,
                ]);
            }
        );
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

    private function computeLaneCap(string $lane): int
    {
        $globalMax = $this->policy->getGlobalMax();
        $lanes = $this->policy->getLanes();

        if (!isset($lanes[$lane])) {
            // Default lane is conservative: 10% of pool, min 1.
            return max(1, (int) floor($globalMax * 0.10));
        }

        $lp = $lanes[$lane];
        $mode = $this->policy->getMode();

        if ($mode === PoolPolicy::MODE_FIXED) {
            $cap = $lp->getMaxConcurrent();
            return max(1, (int) ($cap ?: 1));
        }

        if ($mode === PoolPolicy::MODE_PERCENT) {
            $pct = $lp->getPercent();
            $pct = $pct === null ? 0.10 : $pct;
            return max(1, (int) floor($globalMax * $pct));
        }

        // weighted
        $sum = 0;
        foreach ($lanes as $policy) {
            $w = $policy->getWeight();
            $sum += ($w === null ? 1 : $w);
        }
        if ($sum <= 0) {
            $sum = 1;
        }
        $w = $lp->getWeight();
        $w = $w === null ? 1 : $w;
        return max(1, (int) floor($globalMax * ($w / $sum)));
    }
}

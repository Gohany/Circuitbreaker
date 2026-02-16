<?php

namespace Gohany\Circuitbreaker\Override\Redis;

use Gohany\Circuitbreaker\Consts\CircuitDecisionReason;
use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Override\OverrideDeciderInterface;
use Gohany\Circuitbreaker\Override\OverrideDecision;
use Gohany\Circuitbreaker\Util\Time;
use Psr\Clock\ClockInterface;

final class RedisOverrideDecider implements OverrideDeciderInterface
{
    private RedisOverrideStore $store;
    private ClockInterface $clock;

    public function __construct(RedisOverrideStore $store, ClockInterface $clock)
    {
        $this->store = $store;
        $this->clock = $clock;
    }

    public function decide(CircuitKey $key, CircuitContext $context): ?OverrideDecision
    {
        $raw = $this->store->get($key);
        if (empty($raw)) {
            return null;
        }

        $meta = [];
        if (isset($raw['meta_json']) && $raw['meta_json'] !== '') {
            $decoded = json_decode($raw['meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        if (isset($raw['force_deny']) && $raw['force_deny'] === '1') {
            return new OverrideDecision(false, CircuitDecisionReason::OVERRIDE_FORCE_DENY, null, $meta);
        }

        if (isset($raw['force_allow']) && $raw['force_allow'] === '1') {
            return new OverrideDecision(true, CircuitDecisionReason::OVERRIDE_FORCE_ALLOW, null, $meta);
        }

        $mode = isset($raw['forced_mode']) ? (string) $raw['forced_mode'] : '';
        if ($mode === '') {
            return null;
        }

        $now = Time::toUnixMs($this->clock->now());

        $until = null;
        if (isset($raw['forced_until_ms']) && $raw['forced_until_ms'] !== '') {
            $until = (int) $raw['forced_until_ms'];
        }

        if ($until !== null && $now >= $until) {
            return null;
        }

        if ($mode === CircuitStateMode::OPEN) {
            $retryAfter = $until === null ? null : max(0, $until - $now);
            return new OverrideDecision(false, CircuitDecisionReason::OVERRIDE_FORCED_OPEN, $retryAfter, $meta);
        }

        if ($mode === CircuitStateMode::CLOSED) {
            return new OverrideDecision(true, CircuitDecisionReason::OVERRIDE_FORCED_CLOSED, null, $meta);
        }

        if ($mode === CircuitStateMode::HALF_OPEN) {
            return new OverrideDecision(true, CircuitDecisionReason::OVERRIDE_FORCED_HALF_OPEN, null, $meta);
        }

        return null;
    }
}

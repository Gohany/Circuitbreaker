<?php

namespace Gohany\Circuitbreaker\Override\Redis;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Util\Time;
use Psr\Clock\ClockInterface;

final class RedisOverrideAdmin
{
    private RedisOverrideStore $store;
    private ClockInterface $clock;

    public function __construct(RedisOverrideStore $store, ClockInterface $clock)
    {
        $this->store = $store;
        $this->clock = $clock;
    }

    public function forceAllow(CircuitKey $key, int $ttlMs, array $meta = []): void
    {
        $this->setSwitch($key, true, $ttlMs, $meta);
    }

    public function forceDeny(CircuitKey $key, int $ttlMs, array $meta = []): void
    {
        $this->setSwitch($key, false, $ttlMs, $meta);
    }

    private function setSwitch(CircuitKey $key, bool $allow, int $ttlMs, array $meta): void
    {
        $now = Time::toUnixMs($this->clock->now());
        $until = $ttlMs > 0 ? ($now + $ttlMs) : null;

        $fields = [
            'force_allow' => $allow ? '1' : '',
            'force_deny' => $allow ? '' : '1',
            'forced_mode' => '',
            'forced_until_ms' => $until === null ? '' : (string) $until,
            'reason' => isset($meta['reason']) ? (string) $meta['reason'] : ($allow ? 'admin_force_allow' : 'admin_force_deny'),
            'meta_json' => json_encode($meta),
        ];

        $this->store->set($key, $fields, $ttlMs > 0 ? $ttlMs : null);
    }
}

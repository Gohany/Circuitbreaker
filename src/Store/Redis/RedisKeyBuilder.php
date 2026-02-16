<?php

namespace Gohany\Circuitbreaker\Store\Redis;

use Gohany\Circuitbreaker\Core\CircuitKey;

final class RedisKeyBuilder
{
    private string $prefix;
    private bool $useHumanReadable;

    public function __construct(string $prefix = 'cb', bool $useHumanReadable = false)
    {
        $this->prefix = $prefix;
        $this->useHumanReadable = $useHumanReadable;
    }

    public function stateKey(CircuitKey $key): string
    {
        return $this->base($key) . ':state';
    }

    public function countersKey(CircuitKey $key): string
    {
        return $this->base($key) . ':counters';
    }

    public function bucketKey(CircuitKey $key, int $epochMinute): string
    {
        return $this->base($key) . ':b:' . $epochMinute;
    }

    public function bucketPattern(CircuitKey $key): string
    {
        return $this->base($key) . ':b:*';
    }

    public function overrideKey(CircuitKey $key): string
    {
        return $this->base($key) . ':override';
    }

    public function lockKey(string $name): string
    {
        return $this->prefix . ':lock:' . $name;
    }

    private function base(CircuitKey $key): string
    {
        $dim = $this->dimensionId($key->dimensions);

        if ($this->useHumanReadable) {
            return $this->prefix . ':' . $key->name . ':' . $dim;
        }

        return $this->prefix . ':' . $key->name . ':' . sha1($dim);
    }

    /**
     * @param array<string,mixed> $dimensions
     */
    private function dimensionId(array $dimensions): string
    {
        if (empty($dimensions)) {
            return 'global';
        }

        ksort($dimensions);

        $parts = [];
        foreach ($dimensions as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v);
            } elseif (is_bool($v)) {
                $v = $v ? '1' : '0';
            } elseif ($v === null) {
                $v = 'null';
            } else {
                $v = (string) $v;
            }
            $parts[] = $k . '=' . $v;
        }

        return implode('|', $parts);
    }
}

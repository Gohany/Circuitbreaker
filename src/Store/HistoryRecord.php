<?php

namespace Gohany\Circuitbreaker\Store;

final class HistoryRecord
{
    public int $tsMs;
    public bool $success;
    /** @var string[] */
    public array $signals;
    public int $durationMs;
    /** @var array<string,mixed> */
    public array $attributes;

    /**
     * @param string[] $signals
     * @param array<string,mixed> $attributes
     */
    public function __construct(int $tsMs, bool $success, array $signals = [], int $durationMs = 0, array $attributes = [])
    {
        $this->tsMs = $tsMs;
        $this->success = $success;
        $this->signals = $signals;
        $this->durationMs = $durationMs;
        $this->attributes = $attributes;
    }
}

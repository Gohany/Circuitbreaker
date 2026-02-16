<?php declare(strict_types=1);

namespace Gohany\Retry;

interface JitterInterface
{
    /** 'full', 'pm', or 'none'. */
    public function mode(): string;

    /** Percent as 0–1 float (for plus/minus), or null. */
    public function percent(): ?float;

    /** Absolute jitter window in ms (for plus/minus), or null. */
    public function windowMs(): ?int;

    /**
     * Apply jitter to a nominal delay (milliseconds) and return the randomized delay (>= 0).
     */
    public function apply(int $nominalDelayMs, ?int $seed = null): int;
}

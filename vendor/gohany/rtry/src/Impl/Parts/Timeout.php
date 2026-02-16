<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;
use Gohany\Rtry\Impl\Duration;

final class Timeout extends Part implements PartInterface
{
    public const KEY = 't';
    private int $timeoutMs;

    public function __construct(int $timeoutMs) {
        $this->timeoutMs = $timeoutMs;
    }

    public function timeoutMs(): int
    {
        return $this->timeoutMs;
    }

    public function key(): string
    {
        return Timeout::KEY;
    }

    public function __toString(): string
    {
        return Timeout::KEY . '=' . Duration::formatMs($this->timeoutMs);
    }

    public function applyToPolicy(RtryPolicyInterface $policy): RtryPolicyInterface
    {
        return $policy->setAttemptTimeoutMs($this->timeoutMs);
    }

    public static function make(string $value): PartInterface
    {
        return new Timeout(Duration::parseDurationMs(Timeout::trimKey($value)));
    }
}
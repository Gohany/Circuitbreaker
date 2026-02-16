<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;
use Gohany\Rtry\Impl\Duration;

final class StartAfter extends Part implements PartInterface
{
    public const KEY = 'sa';
    private int $delayMs;

    public function __construct(int $delayMs) {
        $this->delayMs = $delayMs;
    }

    public function delayMs(): int
    {
        return $this->delayMs;
    }

    public function key(): string
    {
        return StartAfter::KEY;
    }

    public function __toString(): string
    {
        return StartAfter::KEY . '=' . Duration::formatMs($this->delayMs);
    }

    public function applyToPolicy(RtryPolicyInterface $policy): RtryPolicyInterface
    {
        return $policy->setStartAfterMs($this->delayMs);
    }

    public static function make(string $value): PartInterface
    {
        return new StartAfter(Duration::parseDurationMs(StartAfter::trimKey($value)));
    }

}

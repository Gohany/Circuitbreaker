<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;
use Gohany\Rtry\Impl\Duration;

final class Cap extends Part implements PartInterface
{
    public const KEY = 'cap';
    private int $capMs;

    public function __construct(int $capMs) {
        $this->capMs = $capMs;
    }

    public function capMs(): int
    {
        return $this->capMs;
    }

    public function key(): string
    {
        return Cap::KEY;
    }

    public function __toString(): string
    {
        return Cap::KEY . '=' . Duration::formatMs($this->capMs);
    }

    public function applyToPolicy(RtryPolicyInterface $policy): RtryPolicyInterface
    {
        return $policy->setCapMs($this->capMs);
    }

    public static function make(string $value): PartInterface
    {
        return new Cap(Duration::parseDurationMs(Cap::trimKey($value)));
    }
}
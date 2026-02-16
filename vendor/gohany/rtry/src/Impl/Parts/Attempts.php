<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;

final class Attempts extends Part implements PartInterface
{
    public const KEY = 'a';
    private int $attempts;

    public function __construct(int $attempts) {
        $this->attempts = $attempts;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function key(): string
    {
        return Attempts::KEY;
    }

    public function __toString(): string
    {
        return Attempts::KEY . '=' . $this->attempts;
    }

    public function applyToPolicy(RtryPolicyInterface $policy): RtryPolicyInterface
    {
        return $policy->setAttempts($this->attempts());
    }

    public static function make(string $value): PartInterface
    {
        return new Attempts(max(1, intval(Attempts::trimKey($value))));
    }
}
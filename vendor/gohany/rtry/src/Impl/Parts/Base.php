<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;

final class Base extends Part implements PartInterface
{
    public const KEY = 'b';
    private float $base;

    public function __construct(float $base) {
        $this->base = $base;
    }

    public function base(): float
    {
        return $this->base;
    }

    public function key(): string
    {
        return Base::KEY;
    }

    public function __toString(): string
    {
        // Trim trailing zeros
        $s = rtrim(rtrim(sprintf('%.6F', $this->base), '0'), '.');
        return Base::KEY . '=' . ($s === '' ? '0' : $s);
    }

    public function applyToPolicy(RtryPolicyInterface $policy): RtryPolicyInterface
    {
        return $policy->setExponentialBase($this->base);
    }

    public static function make(string $value): PartInterface
    {
        return new Base(floatval(Base::trimKey($value)));
    }
}
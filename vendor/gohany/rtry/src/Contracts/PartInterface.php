<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

interface PartInterface
{
    public function key(): string;
    public function __toString(): string;
    public function applyToPolicy(RtryPolicyInterface  $policy): RtryPolicyInterface;
    public static function make(string $value): self;
}
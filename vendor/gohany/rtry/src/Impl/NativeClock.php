<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Psr\Clock\ClockInterface;

class NativeClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
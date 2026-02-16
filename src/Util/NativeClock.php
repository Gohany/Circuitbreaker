<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Util;

use Psr\Clock\ClockInterface;
use DateTimeImmutable;

final class NativeClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}

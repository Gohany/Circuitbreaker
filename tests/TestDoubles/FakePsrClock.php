<?php

declare(strict_types=1);

namespace tests\TestDoubles;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final class FakePsrClock implements ClockInterface
{
    private int $nowMs;

    public function __construct(int $nowMs)
    {
        $this->nowMs = $nowMs;
    }

    public function now(): DateTimeImmutable
    {
        $sec = intdiv($this->nowMs, 1000);
        $ms = $this->nowMs % 1000;

        $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $sec, $ms * 1000), new DateTimeZone('UTC'));
        if ($dt === false) {
            return new DateTimeImmutable('@' . $sec);
        }

        return $dt;
    }

    public function setNowMs(int $nowMs): void
    {
        $this->nowMs = $nowMs;
    }
}

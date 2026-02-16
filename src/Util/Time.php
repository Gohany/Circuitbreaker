<?php

namespace Gohany\Circuitbreaker\Util;

use DateTimeInterface;

final class Time
{
    private function __construct() {}

    public static function toUnixMs(DateTimeInterface $dt): int
    {
        $sec = (int) $dt->format('U');
        $usec = (int) $dt->format('u');
        return ($sec * 1000) + (int) floor($usec / 1000);
    }
}

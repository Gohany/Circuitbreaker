<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

interface SleeperInterface
{
    public function sleepMs(int $milliseconds): void;
}
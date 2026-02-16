<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

class FailureToken
{
    const FIVE_HUNDRED = '5XX';
    const FOUR_HUNDRED = '4XX';
    const ETIMEDOUT = 'ETIMEDOUT';
    const NETWORK_ERROR = 'NETWORK_ERROR';
    const ECONNRESET = 'ECONNRESET';
    const ECONNREFUSED = 'ECONNREFUSED';
    const DEADLOCK = 'DEADLOCK';
    const RATE_LIMITED = 'RATE_LIMITED';

    /**
     * @return array<int,int|string>
     */
    public static function defaults(): array
    {
        // “Starting tokens” enabled by default.
        return [
            self::FIVE_HUNDRED,
            self::ETIMEDOUT,
            self::NETWORK_ERROR,
            self::ECONNRESET,
            self::ECONNREFUSED,
            self::DEADLOCK,
            self::RATE_LIMITED,
        ];
    }
}
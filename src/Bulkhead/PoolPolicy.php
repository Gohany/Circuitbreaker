<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Bulkhead;

/**
 * Defines how a shared pool's lane caps are computed.
 */
final class PoolPolicy
{
    public const MODE_FIXED = 'fixed';
    public const MODE_PERCENT = 'percent';
    public const MODE_WEIGHTED = 'weighted';

    /** @var string */
    private $poolId;
    /** @var int */
    private $globalMax;
    /** @var string */
    private $mode;
    /** @var float */
    private $softBorrowUtilizationThreshold;
    /** @var array<string,LanePolicy> */
    private $lanes;

    /**
     * @param array<string,LanePolicy> $lanes
     */
    public function __construct(
        string $poolId,
        int $globalMax,
        string $mode,
        float $softBorrowUtilizationThreshold,
        array $lanes
    ) {
        $this->poolId = $poolId;
        $this->globalMax = $globalMax;
        $this->mode = $mode;
        $this->softBorrowUtilizationThreshold = $softBorrowUtilizationThreshold;
        $this->lanes = $lanes;
    }

    public function getPoolId(): string
    {
        return $this->poolId;
    }

    public function getGlobalMax(): int
    {
        return $this->globalMax;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getSoftBorrowUtilizationThreshold(): float
    {
        return $this->softBorrowUtilizationThreshold;
    }

    /**
     * @return array<string,LanePolicy>
     */
    public function getLanes(): array
    {
        return $this->lanes;
    }
}

<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

final class CircuitBreakerConfig
{
    /** @var int */
    public $minimumCalls = 20;
    /** @var float */
    public $failureRateToOpen = 0.50;
    /** @var float */
    public $openDurationSeconds = 30.0;

    /**
     * Half-open ramp settings: start at this fraction of max.
     * @var float
     */
    public $halfOpenStartFraction = 0.10;

    /**
     * Half-open ramps to 1.0 over this duration.
     * @var float
     */
    public $halfOpenRampDurationSeconds = 60.0;

    /**
     * Max concurrent permits in half-open at full ramp.
     * @var int
     */
    public $halfOpenMaxConcurrent = 20;

    /**
     * Failures in half-open to reopen.
     * @var int
     */
    public $halfOpenFailuresToOpen = 1;
}

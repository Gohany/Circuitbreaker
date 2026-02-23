<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

final class RetryConfig
{
    /** @var int */
    public $maxAttempts = 3;
    /** @var int */
    public $baseDelayMs = 50;
    /** @var int */
    public $maxDelayMs = 1000;
    /** @var bool */
    public $jitter = true;

    /**
     * @var string[]
     */
    public $retryOn = [\RuntimeException::class];
}

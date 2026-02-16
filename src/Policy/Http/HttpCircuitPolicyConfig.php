<?php

namespace Gohany\Circuitbreaker\Policy\Http;

/**
 * HTTP-focused circuit breaker policy configuration.
 *
 * Intentionally NOT final so teams can extend and override defaults via subclassing.
 */
class HttpCircuitPolicyConfig
{
    public int $openDurationMs = 30000;              // 30s
    public int $openMinDurationMs = 5000;            // clamp minimum

    public int $consecutiveFailuresToOpen = 5;

    public int $halfOpenMaxInFlight = 1;
    public int $halfOpenSuccessesToClose = 2;
    public int $halfOpenFailuresToOpen = 1;

    public bool $denyOnRateLimit = true;

    /** @var string[] */
    public array $failureSignals = ['timeout', 'connection_error', 'http_5xx'];

    /** @var string[] */
    public array $rateLimitSignals = ['http_429', 'rate_limited'];

    public string $counterConsecutiveFailures = 'consecutive_failures';
}

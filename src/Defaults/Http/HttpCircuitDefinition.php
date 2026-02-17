<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Policy\OutcomeClassifierInterface;

/**
 * Defines how a circuit participates in a request.
 *
 * The default builder interprets these flags as:
 * - tenantScoped: key = "{prefix}:{tenantId}" (disabled when tenantId missing)
 * - otherwise: key = "{prefix}:{host}"
 */
final class HttpCircuitDefinition
{
    public CircuitBreakerInterface $breaker;
    public OutcomeClassifierInterface $classifier;
    public string $prefix;
    public bool $tenantScoped;

    public function __construct(
        CircuitBreakerInterface $breaker,
        OutcomeClassifierInterface $classifier,
        string $prefix,
        bool $tenantScoped = false
    ) {
        $this->breaker = $breaker;
        $this->classifier = $classifier;
        $this->prefix = $prefix;
        $this->tenantScoped = $tenantScoped;
    }
}

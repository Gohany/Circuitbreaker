<?php

namespace Gohany\Circuitbreaker\Policy\Fraud;

/**
 * Fraud lockout configuration.
 *
 * Separate from HTTP policy so fraud logic can be composed onto any policy.
 */
class FraudLockoutConfig
{
    public int $lockoutMs = 3600000; // 1h

    /** @var string[] */
    public array $fraudSignals = ['fraud_suspected'];

    public string $metaUntilKey = 'fraud_until_ms';

    /**
     * @param array<string,mixed> $overrides
     */
    public function __construct(array $overrides = [])
    {
        foreach ($overrides as $k => $v) {
            if (property_exists($this, $k)) {
                $this->{$k} = $v;
            }
        }
    }
}

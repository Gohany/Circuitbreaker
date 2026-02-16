<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Rtry;

use Gohany\Rtry\Impl\RtryPolicy;
use Gohany\Rtry\Impl\Parts\Jitter;

final class SaneRetryPolicies
{
    /**
     * Sane default for idempotent HTTP operations.
     * 3 attempts, exponential backoff starting at 100ms, cap at 2s, full jitter.
     */
    public static function defaultHttp(): RtryPolicy
    {
        $policy = new RtryPolicy(3);
        $policy->setStartAfterMs(100);
        $policy->setBackoffMode('exp');
        $policy->setExponentialBase(2.0);
        $policy->setCapMs(2000);
        // We use full jitter to prevent thundering herds in production.
        $policy->setJitterSpec(Jitter::make('100%@full'));
        $policy->setRetryOnTokens(['5XX', 'NETWORK_ERROR', '429']);
        
        return $policy;
    }

    /**
     * Conservative default for non-idempotent operations (e.g. POST).
     * 2 attempts, linear backoff, no jitter by default (or minimal).
     */
    public static function conservativeWrite(): RtryPolicy
    {
        $policy = new RtryPolicy(2);
        $policy->setStartAfterMs(200);
        $policy->setBackoffMode('lin');
        $policy->setCapMs(1000);
        $policy->setRetryOnTokens(['NETWORK_ERROR', '503', '504']);
        
        return $policy;
    }
}

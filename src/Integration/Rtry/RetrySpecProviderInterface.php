<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Rtry;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;

interface RetrySpecProviderInterface
{
    public function getRetrySpec(CircuitKey $key, CircuitContext $context): ?RetrySpec;
}

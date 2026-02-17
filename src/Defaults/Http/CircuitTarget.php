<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;

/**
 * A concrete circuit key/context pair for a single request.
 */
final class CircuitTarget
{
    public CircuitKey $key;
    public ?CircuitContext $context;

    public function __construct(CircuitKey $key, ?CircuitContext $context = null)
    {
        $this->key = $key;
        $this->context = $context;
    }
}

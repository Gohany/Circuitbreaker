<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Psr\Http\Message\RequestInterface;

interface HttpCircuitBuilderInterface
{
    public function buildKey(RequestInterface $request, string $prefix): CircuitKey;

    public function buildContext(RequestInterface $request): CircuitContext;
}

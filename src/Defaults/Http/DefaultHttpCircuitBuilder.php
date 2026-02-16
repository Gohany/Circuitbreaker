<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Psr\Http\Message\RequestInterface;

class DefaultHttpCircuitBuilder implements HttpCircuitBuilderInterface
{
    public function buildKey(RequestInterface $request, string $prefix): CircuitKey
    {
        $host = $request->getUri()->getHost() ?: 'unknown';
        return new CircuitKey($prefix . ':' . $host);
    }

    public function buildContext(RequestInterface $request): CircuitContext
    {
        $tenantId = null;
        if ($request->hasHeader('X-Tenant-Id')) {
            $tenantId = $request->getHeaderLine('X-Tenant-Id');
        }

        return new CircuitContext($tenantId);
    }
}

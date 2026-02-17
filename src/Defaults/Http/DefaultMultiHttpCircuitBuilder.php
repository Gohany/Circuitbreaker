<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Psr\Http\Message\RequestInterface;

/**
 * Default multi-circuit builder:
 * - Primary circuit: host-scoped (same as `DefaultHttpCircuitBuilder`).
 * - Secondary circuit: tenant-scoped, only when `X-Tenant-Id` header is present.
 */
class DefaultMultiHttpCircuitBuilder extends DefaultHttpCircuitBuilder implements MultiHttpCircuitBuilderInterface
{
    public function buildSecondaryKey(RequestInterface $request, string $prefix): ?CircuitKey
    {
        $tenantId = null;
        if ($request->hasHeader('X-Tenant-Id')) {
            $tenantId = $request->getHeaderLine('X-Tenant-Id');
        }

        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        return new CircuitKey($prefix . ':' . $tenantId);
    }

    public function buildSecondaryContext(RequestInterface $request): ?CircuitContext
    {
        return null;
    }
}

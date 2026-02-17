<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Psr\Http\Message\RequestInterface;

/**
 * Default ordered multi-circuit builder.
 *
 * - Context: tenantId from `X-Tenant-Id` (or null).
 * - Key (host-scoped): "{prefix}:{host}" (host defaults to "unknown")
 * - Key (tenant-scoped): "{prefix}:{tenantId}" (disabled when tenant missing)
 */
final class DefaultMultiHttpCircuitsBuilder implements MultiHttpCircuitsBuilderInterface
{
    public function buildTargets(RequestInterface $request, array $definitions): array
    {
        $tenantId = null;
        if ($request->hasHeader('X-Tenant-Id')) {
            $tenantId = $request->getHeaderLine('X-Tenant-Id');
        }
        if ($tenantId === '') {
            $tenantId = null;
        }

        $ctx = new CircuitContext($tenantId);

        $host = $request->getUri()->getHost() ?: 'unknown';

        $out = [];
        foreach ($definitions as $def) {
            if (!$def instanceof HttpCircuitDefinition) {
                continue;
            }

            if ($def->tenantScoped) {
                if ($tenantId === null) {
                    continue;
                }
                $out[] = new CircuitTarget(new CircuitKey($def->prefix . ':' . $tenantId), $ctx);
                continue;
            }

            $out[] = new CircuitTarget(new CircuitKey($def->prefix . ':' . $host), $ctx);
        }

        return $out;
    }
}

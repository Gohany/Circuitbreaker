<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Psr\Http\Message\RequestInterface;

/**
 * Builder for mapping a request into concrete circuit targets for a list of circuit definitions.
 */
interface MultiHttpCircuitsBuilderInterface
{
    /**
     * @param HttpCircuitDefinition[] $definitions Ordered circuit definitions.
     * @return CircuitTarget[] Ordered concrete targets. Implementations may return fewer targets
     *                         if some circuits are disabled for a request.
     */
    public function buildTargets(RequestInterface $request, array $definitions): array;
}

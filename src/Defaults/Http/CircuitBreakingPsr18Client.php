<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class CircuitBreakingPsr18Client implements ClientInterface
{
    private ClientInterface $inner;
    private CircuitBreakerInterface $breaker;
    private string $prefix;
    private HttpCircuitBuilderInterface $builder;

    public function __construct(
        ClientInterface $inner,
        CircuitBreakerInterface $breaker,
        string $prefix = 'http',
        ?HttpCircuitBuilderInterface $builder = null
    ) {
        $this->inner = $inner;
        $this->breaker = $breaker;
        $this->prefix = $prefix;
        $this->builder = $builder ?? new DefaultHttpCircuitBuilder();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $key = null;
        $context = null;

        if ($request instanceof CircuitBreakerRequestInterface) {
            $key = $request->getCircuitKey();
            $context = $request->getCircuitContext();
        }

        $key = $key ?? $this->builder->buildKey($request, $this->prefix);
        $context = $context ?? $this->builder->buildContext($request);

        return $this->breaker->execute($key, $context, function () use ($request) {
            return $this->inner->sendRequest($request);
        });
    }
}

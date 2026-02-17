<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Defaults\Http;

use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Exception\CircuitDeniedException;
use Gohany\Circuitbreaker\Policy\OutcomeClassifierInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PSR-18 client decorator that coordinates an ordered list of circuits for a single HTTP request.
 *
 * Semantics:
 * - Pre-check: calls `decide(...)` for each circuit (in order). Denied => throws `CircuitDeniedException`.
 * - Network: sends the request through the inner PSR-18 client (no circuit wraps the call).
 * - Post-record: classifies and `recordOutcome(...)` for each circuit (in order).
 *   If recording fails for one circuit, it will still attempt to record the remaining circuits.
 *   After all attempts, failures are logged at `critical`.
 * - Final gate: re-checks each circuit; if any denies, throws `CircuitDeniedException`.
 */
class MultiCircuitBreakingPsr18Client implements ClientInterface
{
    private ClientInterface $inner;

    /** @var HttpCircuitDefinition[] */
    private array $definitions;
    private MultiHttpCircuitsBuilderInterface $builder;
    private LoggerInterface $logger;

    public function __construct(
        ClientInterface $inner,
        array $definitions,
        ?MultiHttpCircuitsBuilderInterface $builder = null,
        ?LoggerInterface $logger = null
    ) {
        $this->inner = $inner;
        $this->definitions = $definitions;
        $this->builder = $builder ?? new DefaultMultiHttpCircuitsBuilder();
        $this->logger = $logger ?? new NullLogger();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $targets = null;
        if ($request instanceof IterableCircuitBreakerRequestInterface) {
            $targets = [];
            foreach ($request as $t) {
                if ($t instanceof CircuitTarget) {
                    $targets[] = $t;
                }
            }
        }
        if ($targets === null) {
            $targets = $this->builder->buildTargets($request, $this->definitions);
        }

        // Pre-check all circuits in order.
        foreach ($this->iterateCircuits($targets) as $row) {
            $breaker = $row['breaker'];
            $key = $row['key'];
            $ctx = $row['context'];
            $d = $breaker->decide($key, $ctx);
            if (!$d->allowed) {
                throw new CircuitDeniedException($d->reason, $d->retryAfterMs);
            }
        }

        $response = null;
        $error = null;

        try {
            $response = $this->inner->sendRequest($request);
        } catch (\Throwable $t) {
            $error = $t;
        }

        $recordFailures = [];
        foreach ($this->iterateCircuits($targets) as $row) {
            $breaker = $row['breaker'];
            $classifier = $row['classifier'];
            $key = $row['key'];
            $ctx = $row['context'];
            $idx = $row['index'];

            $attrs = [
                'duration_ms' => null,
                'circuit' => $key->name,
                'dimensions' => $key->dimensions,
                'circuit_index' => $idx,
            ];

            $outcome = $classifier->classify($response, $error, $attrs);

            try {
                $breaker->recordOutcome($key, $ctx, $outcome);
            } catch (\Throwable $t) {
                $recordFailures[] = [
                    'index' => $idx,
                    'circuit' => $key->name,
                    'dimensions' => $key->dimensions,
                    'error' => $t->getMessage(),
                ];
            }
        }

        if (!empty($recordFailures)) {
            $this->logger->critical('Failed to record one or more circuit outcomes', [
                'failures' => $recordFailures,
            ]);
        }

        // Final gate: if any circuit is currently denying, throw.
        foreach ($this->iterateCircuits($targets) as $row) {
            $breaker = $row['breaker'];
            $key = $row['key'];
            $ctx = $row['context'];
            $d = $breaker->decide($key, $ctx);
            if (!$d->allowed) {
                throw new CircuitDeniedException($d->reason, $d->retryAfterMs);
            }
        }

        if ($error !== null) {
            throw $error;
        }

        /** @var ResponseInterface $response */
        return $response;
    }

    /**
     * @param CircuitTarget[] $targets
     * @return iterable<array{index:int, breaker:CircuitBreakerInterface, classifier:OutcomeClassifierInterface, key:CircuitKey, context:CircuitContext}>
     */
    private function iterateCircuits(array $targets): iterable
    {
        $i = 0;
        foreach ($targets as $target) {
            if (!isset($this->definitions[$i])) {
                break;
            }
            $def = $this->definitions[$i];
            if (!$def instanceof HttpCircuitDefinition) {
                $i++;
                continue;
            }

            $ctx = $target->context;
            if ($ctx === null) {
                $ctx = new CircuitContext(null);
            }

            yield [
                'index' => $i,
                'breaker' => $def->breaker,
                'classifier' => $def->classifier,
                'key' => $target->key,
                'context' => $ctx,
            ];

            $i++;
        }
    }
}

<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Rtry;

use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Exception\CircuitDeniedException;
use Gohany\Circuitbreaker\Policy\OutcomeClassifierInterface;
use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryDeciderInterface;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicy as ConcreteRtryPolicy;
use Gohany\Rtry\Impl\Deciders\AlwaysRetryDecider;
use Throwable;

final class RtryRetryExecutor implements RetryExecutorInterface
{
    private OutcomeClassifierInterface $classifier;
    private Retry $runner;

    public function __construct(OutcomeClassifierInterface $classifier, ?Retry $runner = null)
    {
        $this->classifier = $classifier;
        $this->runner = $runner ?? new Retry();
    }

    public function try(CircuitBreakerInterface $breaker, CircuitKey $key, CircuitContext $context, callable $operation, $retryPolicyOrSpec)
    {
        $policy = $retryPolicyOrSpec;
        if ($retryPolicyOrSpec instanceof RetrySpec) {
            $policy = $retryPolicyOrSpec->getPolicy();
        }

        $deciders = [
            new ClassifierRetryDecider($this->classifier, $key, $context),
            new CircuitHealthRetryDecider($breaker, $key, $context)
        ];

        $retryPolicy = $policy;
        if ($policy instanceof ConcreteRtryPolicy) {
            // We clone to avoid modifying the original policy if it's shared
            $retryPolicy = clone $policy;
            
            // Preserve existing decider if it's not the default AlwaysRetryDecider
            $existingDecider = $policy->decider();
            if ($existingDecider !== null && !($existingDecider instanceof AlwaysRetryDecider)) {
                $deciders[] = $existingDecider;
            }

            $retryPolicy->setRetryDecider(new CompositeDecider(...$deciders));
        }

        $runner = $this->runner;
        // Ensure we use the clock from the breaker if possible? 
        // For now, default is fine.
        
        $counter = new AttemptCounterHook();
        $runner->setBetweenAttemptsHook([$counter, 'recordBetween']);
        $runner->setOnGiveUpHook([$counter, 'recordGiveUp']);

        try {
            $outcome = $runner->try($operation, $retryPolicy, [
                'circuit_key' => $key,
                'circuit_context' => $context,
            ]);

            $context->attributes['retry_attempts'] = $counter->getAttempts();

            if (!$outcome->isSuccess()) {
                // If the loop finished with a failure, check if it was because the circuit was denied
                $decision = $breaker->decide($key, $context);
                if (!$decision->allowed) {
                    throw new CircuitDeniedException($decision->reason, $decision->retryAfterMs);
                }
                throw $outcome->error();
            }

            return $outcome->result();
        } catch (Throwable $e) {
            $context->attributes['retry_attempts'] = $counter->getAttempts();
            
            if (!($e instanceof CircuitDeniedException)) {
                $decision = $breaker->decide($key, $context);
                if (!$decision->allowed) {
                    throw new CircuitDeniedException($decision->reason, $decision->retryAfterMs);
                }
            }
            
            throw $e;
        }
    }
}

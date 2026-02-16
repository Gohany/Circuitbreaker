<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

interface RetryHooksInterface
{
    /**
     * Runs after a failed attempt when we WILL retry, before sleeping.
     * $sleepMs is the chosen backoff for the upcoming sleep.
     *
     * Signature: function(
     *   AttemptContextInterface $context,
     *   AttemptOutcomeInterface $outcome,
     *   RetryPolicyInterface $policy,
     *   int $sleepMs,
     *   array $effectiveContext
     * ): void
     */
    public function setBetweenAttemptsHook(callable $hook): void;

    /**
     * Runs when we give up (decider=false, attempts exhausted, or deadline).
     *
     * Signature: function(
     *   AttemptContextInterface $context,
     *   AttemptOutcomeInterface $outcome,
     *   RetryPolicyInterface $policy,
     *   array $effectiveContext
     * ): void
     */
    public function setOnGiveUpHook(callable $hook): void;
}
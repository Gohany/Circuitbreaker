<?php declare(strict_types=1);

namespace Gohany\Retry;

/**
 * Executes an operation under a retry policy.
 */
interface RetryerInterface
{
    /**
     * Execute an operation under the provided retry policy.
     *
     * @template TReturn
     * @param callable(AttemptContextInterface): TReturn $operation Operation to execute. Receives the attempt context.
     * @param RetryPolicyInterface $policy Compiled retry policy.
     * @param array<string,mixed> $context Arbitrary key/value tags for observability.
     * @return TReturn
     * @throws \Throwable Last error if all attempts (and hedges) fail.
     */
    public function try(callable $operation, RetryPolicyInterface $policy, array $context = []): AttemptOutcomeInterface;
}

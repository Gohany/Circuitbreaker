<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryPolicyInterface;
use Gohany\Retry\RetryerInterface;
use Gohany\Rtry\Contracts\RetryHooksInterface;
use Gohany\Rtry\Contracts\RtryAttemptContextInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;
use Gohany\Rtry\Contracts\SleeperInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Retry service implementing Gohany\Retry\RetryerInterface.
 *
 * Notes:
 *  - Per-attempt timeouts are advisory; this class cannot forcibly cancel a running callable.
 *  - Hedging is not performed in-process.
 */
final class Retry implements RetryerInterface, RetryHooksInterface
{
    private ClockInterface $clock;
    private SleeperInterface $sleeper;
    private LoggerInterface $logger;

    /** @var null|callable */
    private $betweenHook = null;
    /** @var null|callable */
    private $onGiveUpHook = null;

    public function __construct(?ClockInterface $clock = null, ?SleeperInterface $sleeper = null, ?LoggerInterface $logger = null)
    {
        $this->clock = $clock ?? new NativeClock();
        $this->sleeper = $sleeper ?? new NativeSleeper();
        $this->logger = $logger ?? new NullLogger();
    }

    public function setBetweenAttemptsHook(callable $hook): void
    {
        $this->betweenHook = $hook;
    }

    public function setOnGiveUpHook(callable $hook): void
    {
        $this->onGiveUpHook = $hook;
    }

    /**
     * {@inheritdoc}
     */
    public function try(callable $operation, RetryPolicyInterface $policy, array $context = []): AttemptOutcomeInterface
    {
        $maxAttempts = max(1, $policy->attempts());
        [$firstMono, $deadlineAt] = $this->initTiming($policy);

        $this->logger->debug('retry.start', [
            'attempts' => $maxAttempts,
            'start_after_ms' => $policy->startAfterMs(),
            'attempt_timeout_ms' => $policy->attemptTimeoutMs(),
            'deadline_budget_ms' => $policy->deadlineBudgetMs(),
            'context_keys' => array_keys($context),
        ]);

        $delayBeforeFirst = max(0, $policy->startAfterMs());
        if ($delayBeforeFirst > 0) {
            $this->enforceDeadlineOrSleep($delayBeforeFirst, $deadlineAt);
        }

        $lastError = null;
        $scheduledDelayForAttempt = $delayBeforeFirst;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {

            [$elapsedMs, $remainingBudgetMs] = $this->computeElapsedAndRemaining($firstMono, $deadlineAt);

            $attemptContext = $this->newAttemptContext(
                $attempt,
                $maxAttempts,
                $scheduledDelayForAttempt,
                $elapsedMs,
                $remainingBudgetMs,
                $context
            );

            $this->ensureTimeLeftOrGiveUp(
                $attemptContext,
                $deadlineAt,
                $policy,
                $lastError,
                $headers ?? []
            );

            try {

                $result = $operation($attemptContext);

                $this->logger->info('retry.success', [
                    'attempt' => $attempt,
                    'total_elapsed_ms' => $elapsedMs,
                ]);

                return new RtryAttemptOutcome($result);

            } catch (Throwable $e) {

                $lastError = $e;
                $statusCode = null;
                $tags = [];
                $minDelayMs = null;
                $notBeforeMs = null;
                $headers = [];

                if ($policy instanceof RtryPolicyInterface && $policy->failureClassifier() !== null) {

                    $metadata     = $policy->failureClassifier()->classify($e);
                    $statusCode   = $metadata->getStatusCode();
                    $tags         = $metadata->getTags();
                    $ctxPatch     = $metadata->getContextPatch();
                    $minDelayMs   = $metadata->getMinNextDelayMs();
                    $notBeforeMs  = $metadata->getNotBeforeUnixMs();
                    $headers      = $metadata->getHeaders();

                    if (!empty($ctxPatch)) {
                        foreach ($ctxPatch as $k => $v) {
                            $context[$k] = $v;
                        }
                    }

                    $this->logger->warning('retry.failure.classified', [
                        'attempt' => $attempt,
                        'exception_class' => get_class($e),
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'status_code' => $statusCode,
                        'tags' => $tags,
                        'min_next_delay_ms' => $minDelayMs,
                        'not_before_unix_ms' => $notBeforeMs,
                        'header_hints_present' => !empty($headers),
                    ]);

                } else {
                    $this->logger->warning('retry.failure.unclassified', [
                        'attempt' => $attempt,
                        'exception_class' => get_class($e),
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                    ]);
                }

                $outcome = new RtryAttemptOutcome(null, $e, $statusCode, $tags);

                $shouldRetry = $policy->decider()->shouldRetry($outcome, $attemptContext);

                $this->logger->debug('retry.decision', [
                    'attempt' => $attempt,
                    'should_retry' => $shouldRetry,
                    'status_code' => $statusCode,
                    'tags' => $tags,
                ]);

                if (!$shouldRetry || $attempt === $maxAttempts) {
                    $this->runGiveUpHookOnce($attemptContext, $outcome, $policy, $headers);
                    throw $e;
                }

                $sleepMs = $policy->nextDelayMs($attempt + 1);
                $follow = $policy->followHeaders();

                // Respect classifier minimums and absolute not-before
                if ($follow && $minDelayMs !== null) {
                    $sleepMs = max($sleepMs, 0, $minDelayMs);
                }

                if ($follow && $notBeforeMs !== null) {
                    $nowMs = (int) round(((float) $this->clock->now()->format('U.u')) * 1000.0);
                    $delta = $notBeforeMs - $nowMs;
                    if ($delta > 0) {
                        $sleepMs = max($sleepMs, $delta);
                    }
                }

                if ($deadlineAt !== null && !$this->hasTimeLeft($deadlineAt, $sleepMs)) {
                    $this->runGiveUpHookOnce($attemptContext, $outcome, $policy, $headers);
                    throw $e;
                }

                if ($this->betweenHook) {
                    try {
                        ($this->betweenHook)($attemptContext, $outcome, $policy, $sleepMs, $headers);
                    } catch (\Throwable $_hookErr) {
                        // Hooks must not break retry — swallow
                    }
                }

                $this->sleeper->sleepMs($sleepMs);
                $scheduledDelayForAttempt = $sleepMs;
            }
        }

        $outcome = (new RtryAttemptOutcome())
            ->setError($lastError ?: new \RuntimeException('unexpected fall-through'));

        $attemptContext = $this->newAttemptContext(
            $maxAttempts,
            $maxAttempts,
            $scheduledDelayForAttempt,
            (int) round((microtime(true) - $firstMono) * 1000),
            0,
            $context
        );

        $this->runGiveUpHookOnce($attemptContext, $outcome, $policy, $headers ?? []);
        return $outcome;
    }

    private function initTiming(RetryPolicyInterface $policy): array
    {
        $firstMono = microtime(true);

        $deadlineAt = null;
        $deadlineBudgetMs = $policy->deadlineBudgetMs();
        if ($deadlineBudgetMs !== null) {
            $deadlineAt = $this->clock->now()->modify(sprintf('+%d milliseconds', $deadlineBudgetMs));
        }

        return [$firstMono, $deadlineAt];
    }

    private function enforceDeadlineOrSleep(int $delayMs, ?\DateTimeImmutable $deadlineAt): void
    {
        $delayMs = max(0, $delayMs);
        if ($delayMs === 0) {
            return;
        }
        if ($deadlineAt !== null && !$this->hasTimeLeft($deadlineAt, $delayMs)) {
            throw new \RuntimeException('Retry: start-after delay exceeds deadline.');
        }

        $this->logger->debug('retry.sleep.before_first', [
            'delay_ms' => $delayMs,
        ]);

        $this->sleeper->sleepMs($delayMs);
    }

    private function computeElapsedAndRemaining(float $firstMono, ?\DateTimeImmutable $deadlineAt): array
    {
        $elapsedMs = (int) round((microtime(true) - $firstMono) * 1000);
        $remainingBudgetMs = $deadlineAt !== null ? $this->remainingMsUntil($deadlineAt) : null;
        return [$elapsedMs, $remainingBudgetMs];
    }

    /**
     * If there is a deadline, and it has been exceeded, run the give-up hook and throw.
     */
    private function ensureTimeLeftOrGiveUp(
        RtryAttemptContextInterface $attemptContext,
        ?\DateTimeImmutable $deadlineAt,
        RetryPolicyInterface $policy,
        ?\Throwable $lastError,
        array $headers = []
    ): void {

        if ($deadlineAt === null) {
            return;
        }

        $remainingBudgetMs = $this->remainingMsUntil($deadlineAt);
        if ($remainingBudgetMs > 0) {
            return;
        }

        $attemptContext->setRemainingBudgetMs(0);

        $outcome = (new RtryAttemptOutcome())
            ->setError($lastError ?: new \RuntimeException('deadline exceeded'));

        $this->runGiveUpHookOnce($attemptContext, $outcome, $policy, $headers);

        throw ($lastError ?: new \RuntimeException('Retry: deadline exceeded.'));
    }

    private function newAttemptContext(
        int $attempt,
        int $maxAttempts,
        int $scheduledDelayForAttempt,
        int $elapsedMs,
        ?int $remainingBudgetMs,
        array $context
    ): RtryAttemptContextInterface {

        $this->logger->info('retry.attempt', [
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'scheduled_delay_ms' => $scheduledDelayForAttempt,
            'elapsed_ms' => $elapsedMs,
            'remaining_budget_ms' => $remainingBudgetMs,
        ]);

        return new RtryAttemptContext(
            $attempt,
            $maxAttempts,
            $scheduledDelayForAttempt,
            $elapsedMs,
            $remainingBudgetMs,
            $context
        );
    }

    private function hasTimeLeft(\DateTimeInterface $deadlineAt, int $sleepMs): bool
    {
        $remaining = $this->remainingMsUntil($deadlineAt);

        return $remaining > $sleepMs;
    }

    private function remainingMsUntil(\DateTimeInterface $deadlineAt): int
    {
        $now = $this->clock->now();
        $delta = ($deadlineAt->format('U.u') - $now->format('U.u')) * 1000.0;

        return (int) max(0, round($delta));
    }

    private function runGiveUpHookOnce(
        AttemptContextInterface $ctx,
        AttemptOutcomeInterface $outcome,
        RetryPolicyInterface $policy,
        array $headers = []
    ): void {

        $error = $outcome->error();
        $this->logger->error('retry.give_up', [
            'attempt' => $ctx->attemptNumber(),
            'max_attempts' => $ctx->maxAttempts(),
            'total_elapsed_ms' => $ctx->elapsedSinceFirstMs(),
            'last_error_class' => $error !== null ? get_class($error) : '',
            'last_error_message' => $error !== null ? $error->getMessage() : '',
            'status_code' => $outcome->statusCode(),
            'tags' => $outcome->tags(),
        ]);

        if (!$this->onGiveUpHook) {
            return;
        }
        try {
            ($this->onGiveUpHook)($ctx, $outcome, $policy, $headers);
        } catch (\Throwable $_) {
            // Hooks must never throw into callers
        }
    }

}





<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use DateInterval;
use DateTimeImmutable;
use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryDeciderInterface;
use Gohany\Retry\RetryPolicyInterface;
use Gohany\Rtry\Contracts\FailureClassifierInterface;
use Gohany\Rtry\Contracts\FailureMetadataInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;
use Gohany\Rtry\Contracts\SleeperInterface;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryAttemptContext;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

/**
 * Retry tests (AAA) with no anonymous test classes and no adapter.
 * For the classifier/follow-headers path we mock the richer policy interface directly.
 */
final class RtryTest extends TestCase
{

    private function newRetry(TestFixedClock $clock, TestSleeperSpy $sleeper): Retry
    {
        return new Retry($clock, $sleeper, new NullLogger());
    }

    // ================== TESTS ==================

    public function test_first_attempt_success_no_sleep(): void
    {
        // Arrange
        $clock = new TestFixedClock();
        $sleeper = new TestSleeperSpy();
        $retry = $this->newRetry($clock, $sleeper);

        $policy = $this->createMock(RetryPolicyInterface::class);
        $policy->method('attempts')->willReturn(3);
        $policy->method('startAfterMs')->willReturn(0);
        $policy->method('attemptTimeoutMs')->willReturn(null);
        $policy->method('deadlineBudgetMs')->willReturn(null);
        $policy->method('nextDelayMs')->willReturn(100);
        $policy->method('followHeaders')->willReturn(false);

        $calls = 0;
        $operation = function () use (&$calls) {
            $calls++;

            return 'ok';
        };

        // Act
        $outcome = $retry->try($operation, $policy);

        // Assert
        $this->assertInstanceOf(AttemptOutcomeInterface::class, $outcome);
        $this->assertSame(1, $calls);
        $this->assertSame([], $sleeper->sleeps);
    }

    public function test_start_after_causes_initial_sleep_then_success(): void
    {
        // Arrange
        $clock = new TestFixedClock();
        $sleeper = new TestSleeperSpy();
        $retry = $this->newRetry($clock, $sleeper);

        $policy = $this->createMock(RetryPolicyInterface::class);
        $policy->method('attempts')->willReturn(1);
        $policy->method('startAfterMs')->willReturn(200);
        $policy->method('attemptTimeoutMs')->willReturn(null);
        $policy->method('deadlineBudgetMs')->willReturn(null);
        $policy->method('nextDelayMs')->willReturn(0);
        $policy->method('followHeaders')->willReturn(false);

        $operation = function () {
            return 'ok';
        };

        // Act
        $outcome = $retry->try($operation, $policy);

        // Assert
        $this->assertInstanceOf(AttemptOutcomeInterface::class, $outcome);
        $this->assertSame([200], $sleeper->sleeps);
    }

    public function test_retry_once_then_success_sleeps_base_delay(): void
    {
        // Arrange
        $clock = new TestFixedClock();
        $sleeper = new TestSleeperSpy();
        $retry = $this->newRetry($clock, $sleeper);

        $policy = $this->createMock(RetryPolicyInterface::class);
        $policy->method('attempts')->willReturn(3);
        $policy->method('startAfterMs')->willReturn(0);
        $policy->method('attemptTimeoutMs')->willReturn(null);
        $policy->method('deadlineBudgetMs')->willReturn(null);
        $policy->method('nextDelayMs')->willReturnCallback(function (int $attempt) {
            return $attempt === 2 ? 250 : 0;
        });
        $decider = $this->createMock(RetryDeciderInterface::class);
        $policy->method('followHeaders')->willReturn(false);
        $decider->method('shouldRetry')->willReturn(true);
        $policy->method('decider')->willReturn($decider);

        $calls = 0;
        $operation = function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('boom');
            }

            return 'ok';
        };

        // Act
        $outcome = $retry->try($operation, $policy);

        // Assert
        $this->assertInstanceOf(AttemptOutcomeInterface::class, $outcome);
        $this->assertSame(2, $calls);
        $this->assertSame([250], $sleeper->sleeps);
    }

    public function test_follow_headers_true_applies_classifier_min_and_not_before_without_adapter(): void
    {
        // Arrange
        $clock = new TestFixedClock('2024-01-01T00:00:00+00:00');
        $sleeper = new TestSleeperSpy();
        $retry = $this->newRetry($clock, $sleeper);

        // Mock the *rich* policy interface directly (no adapter)
        $policy = $this->createMock(RtryPolicyInterface::class);
        $policy->method('backoffMode')->willReturn('lin');
        $policy->method('capMs')->willReturn(0);
        $policy->method('exponentialBase')->willReturn(0.0);
        $policy->method('jitter')->willReturn(null);
        $policy->method('hedge')->willReturn(null);

        $policy->method('attempts')->willReturn(2);
        $policy->method('startAfterMs')->willReturn(0);
        $policy->method('attemptTimeoutMs')->willReturn(null);
        $policy->method('deadlineBudgetMs')->willReturn(null);
        $policy->method('nextDelayMs')->willReturn(100); // base
        $policy->method('followHeaders')->willReturn(true);

        $decider = $this->createMock(RetryDeciderInterface::class);
        $policy->method('followHeaders')->willReturn(false);
        $decider->method('shouldRetry')->willReturn(true);
        $policy->method('decider')->willReturn($decider);

        $notBefore = ((int)(new DateTimeImmutable('2024-01-01T00:00:00+00:00'))->format('U')) * 1000 + 500;
        $meta = new TestClassifierMetadata(429, ['retry-after'], [], 300, $notBefore, ['Retry-After' => '1']);
        $clf = new TestClassifier($meta);
        $policy->method('failureClassifier')->willReturn($clf);

        $calls = 0;
        $operation = function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('throttle');
            }

            return 'ok';
        };

        // Act
        $outcome = $retry->try($operation, $policy);

        // Assert -> max(base 100, min 300, not-before 500) = 500
        $this->assertInstanceOf(AttemptOutcomeInterface::class, $outcome);
        $this->assertSame([500], $sleeper->sleeps);
        $this->assertSame(2, $calls);
    }

    public function test_follow_headers_false_uses_base_delay_only(): void
    {
        // Arrange
        $clock = new TestFixedClock();
        $sleeper = new TestSleeperSpy();
        $retry = $this->newRetry($clock, $sleeper);

        $policy = $this->createMock(RetryPolicyInterface::class);
        $policy->method('attempts')->willReturn(2);
        $policy->method('startAfterMs')->willReturn(0);
        $policy->method('attemptTimeoutMs')->willReturn(null);
        $policy->method('deadlineBudgetMs')->willReturn(null);
        $policy->method('nextDelayMs')->willReturn(200);
        $policy->method('followHeaders')->willReturn(false);

        $decider = $this->createMock(RetryDeciderInterface::class);
        $policy->method('followHeaders')->willReturn(false);
        $decider->method('shouldRetry')->willReturn(true);
        $policy->method('decider')->willReturn($decider);

        $calls = 0;
        $operation = function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('err');
            }

            return 'ok';
        };

        // Act
        $outcome = $retry->try($operation, $policy);

        // Assert
        $this->assertInstanceOf(AttemptOutcomeInterface::class, $outcome);
        $this->assertSame([200], $sleeper->sleeps);
        $this->assertSame(2, $calls);
    }

    public function test_deadline_prevents_sleep_and_triggers_give_up_hook(): void
    {
        // Arrange
        $clock = new TestFixedClock();
        $sleeper = new TestSleeperSpy();
        $retry = $this->newRetry($clock, $sleeper);

        $policy = $this->createMock(RetryPolicyInterface::class);
        $policy->method('attempts')->willReturn(2);
        $policy->method('startAfterMs')->willReturn(0);
        $policy->method('attemptTimeoutMs')->willReturn(null);
        $policy->method('deadlineBudgetMs')->willReturn(300); // small budget
        $policy->method('nextDelayMs')->willReturn(1000);     // would exceed budget
        $policy->method('followHeaders')->willReturn(false);

        $decider = $this->createMock(RetryDeciderInterface::class);
        $policy->method('followHeaders')->willReturn(false);
        $decider->method('shouldRetry')->willReturn(true);
        $policy->method('decider')->willReturn($decider);

        $gaveUp = false;
        $retry->setOnGiveUpHook(
            function (AttemptContextInterface $ctx, AttemptOutcomeInterface $outcome) use (&$gaveUp) {
                $gaveUp = true;
            }
        );

        $operation = function () {
            throw new \RuntimeException('always fail');
        };

        // Act & Assert
        try {
            $retry->try($operation, $policy);
            $this->fail('Expected exception');
        } catch (\RuntimeException $_) {
            $this->assertSame([], $sleeper->sleeps, 'deadline should prevent sleeping');
            $this->assertTrue($gaveUp, 'give-up hook should run');
        }
    }

    public function test_between_attempts_hook_receives_effective_sleep_value(): void
    {
        // Arrange
        $clock = new TestFixedClock();
        $sleeper = new TestSleeperSpy();
        $retry = $this->newRetry($clock, $sleeper);

        $policy = $this->createMock(RetryPolicyInterface::class);
        $policy->method('attempts')->willReturn(2);
        $policy->method('startAfterMs')->willReturn(0);
        $policy->method('attemptTimeoutMs')->willReturn(null);
        $policy->method('deadlineBudgetMs')->willReturn(null);
        $policy->method('nextDelayMs')->willReturn(123);
        $policy->method('followHeaders')->willReturn(false);

        $decider = $this->createMock(RetryDeciderInterface::class);
        $policy->method('followHeaders')->willReturn(false);
        $decider->method('shouldRetry')->willReturn(true);
        $policy->method('decider')->willReturn($decider);

        $seen = [];
        $retry->setBetweenAttemptsHook(
            function (
                AttemptContextInterface $ctx,
                AttemptOutcomeInterface $outcome,
                RetryPolicyInterface $_pol,
                int $sleepMs
            ) use (&$seen) {
                $seen[] = $sleepMs;
            }
        );

        $operation = function (RtryAttemptContext $context) {
            if ($context->attemptNumber() === 1) {
                throw new \RuntimeException('nope');
            }
        };

        // Act & Assert

        $retry->try($operation, $policy);

        // Assert
        $this->assertSame([123], $seen);
        $this->assertSame([123], $sleeper->sleeps);
    }
}

final class TestFixedClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $iso = '2024-01-01T00:00:00+00:00')
    {
        $this->now = new DateTimeImmutable($iso);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceMs(int $ms): void
    {
        $this->now = $this->now->add(new DateInterval('PT0S'))->modify("+{$ms} milliseconds");
    }
}

/** Sleeper spy that records all sleepMs calls. */
final class TestSleeperSpy implements SleeperInterface
{
    /** @var int[] */
    public array $sleeps = [];

    public function sleepMs(int $ms): void
    {
        $this->sleeps[] = $ms;
    }
}

/** Minimal metadata used by Retry failure-header path. */
final class TestClassifierMetadata implements FailureMetadataInterface
{
    private int $status;
    private array $tags;
    private array $ctx;
    private ?int $min;
    private ?int $notBeforeUnixMs;
    private array $headers;

    public function __construct(
        int $status = 429,
        array $tags = ['retry-after'],
        array $ctx = [],
        ?int $minNextDelay = null,
        ?int $notBeforeUnixMs = null,
        array $headers = []
    ) {
        $this->status = $status;
        $this->tags = $tags;
        $this->ctx = $ctx;
        $this->min = $minNextDelay;
        $this->notBeforeUnixMs = $notBeforeUnixMs;
        $this->headers = $headers;
    }

    public function getStatusCode(): ?int
    {
        return $this->status;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getContextPatch(): array
    {
        return $this->ctx;
    }

    public function getMinNextDelayMs(): ?int
    {
        return $this->min;
    }

    public function getNotBeforeUnixMs(): ?int
    {
        return $this->notBeforeUnixMs;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}

/** Classifier stub that always returns the configured metadata. */
final class TestClassifier implements FailureClassifierInterface
{
    private FailureMetadataInterface $meta;

    public function __construct(FailureMetadataInterface $m)
    {
        $this->meta = $m;
    }

    public function classify(\Throwable $e): FailureMetadataInterface
    {
        return $this->meta;
    }
}
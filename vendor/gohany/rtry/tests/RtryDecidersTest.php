<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryDeciderInterface;
use Gohany\Rtry\Impl\Deciders\AlwaysRetryDecider;
use Gohany\Rtry\Impl\Deciders\CompositeDecider;
use Gohany\Rtry\Impl\Deciders\OnTokensDecider;
use PHPUnit\Framework\TestCase;

final class RtryDecidersTest extends TestCase
{
    private function outcome(bool $success, ?\Throwable $error = null, ?int $status = null, array $tags = []): AttemptOutcomeInterface
    {
        $o = $this->createMock(AttemptOutcomeInterface::class);
        $o->method('isSuccess')->willReturn($success);
        $o->method('error')->willReturn($error);
        $o->method('statusCode')->willReturn($status);
        $o->method('tags')->willReturn($tags);
        return $o;
    }

    private function context(): AttemptContextInterface
    {
        return $this->createMock(AttemptContextInterface::class);
    }

    public function test_always_retry_decider_returns_false_on_success(): void
    {
        // Arrange
        $decider = new AlwaysRetryDecider();
        $outcome = $this->outcome(true, null, 200, []);
        $ctx = $this->context();

        // Act
        $ans = $decider->shouldRetry($outcome, $ctx);

        // Assert
        $this->assertFalse($ans);
    }

    public function test_always_retry_decider_returns_true_on_error(): void
    {
        // Arrange
        $decider = new AlwaysRetryDecider();
        $outcome = $this->outcome(false, new \RuntimeException('boom'), 500, []);
        $ctx = $this->context();

        // Act
        $ans = $decider->shouldRetry($outcome, $ctx);

        // Assert
        $this->assertTrue($ans);
    }

    public function test_composite_decider_returns_false_with_no_children(): void
    {
        // Arrange
        $dec = new CompositeDecider();
        $out = $this->outcome(false, new \RuntimeException('x'));
        $ctx = $this->context();

        // Act
        $ans = $dec->shouldRetry($out, $ctx);

        // Assert
        $this->assertFalse($ans);
    }

    public function test_composite_decider_returns_true_if_any_child_true_and_short_circuits(): void
    {
        // Arrange
        $d1 = $this->createMock(RetryDeciderInterface::class);
        $d2 = $this->createMock(RetryDeciderInterface::class);
        $out = $this->outcome(false, new \RuntimeException('x'));
        $ctx = $this->context();

        $d1->expects($this->once())->method('shouldRetry')->with($out, $ctx)->willReturn(true);
        $d2->expects($this->never())->method('shouldRetry'); // short-circuit

        $dec = new CompositeDecider($d1, $d2);

        // Act
        $ans = $dec->shouldRetry($out, $ctx);

        // Assert
        $this->assertTrue($ans);
    }

    public function test_composite_decider_all_false_returns_false(): void
    {
        // Arrange
        $d1 = $this->createMock(RetryDeciderInterface::class);
        $d2 = $this->createMock(RetryDeciderInterface::class);
        $out = $this->outcome(false, new \RuntimeException('x'));
        $ctx = $this->context();

        $d1->method('shouldRetry')->willReturn(false);
        $d2->method('shouldRetry')->willReturn(false);

        $dec = new CompositeDecider($d1, $d2);

        // Act
        $ans = $dec->shouldRetry($out, $ctx);

        // Assert
        $this->assertFalse($ans);
    }

    public function test_on_tokens_decider_matches_numeric_status_codes(): void
    {
        // Arrange
        $dec = new OnTokensDecider([429, 503]);
        $ctx = $this->context();

        // Act & Assert
        $this->assertTrue($dec->shouldRetry($this->outcome(false, new \RuntimeException(), 429), $ctx), '429 should match');
        $this->assertTrue($dec->shouldRetry($this->outcome(false, new \RuntimeException(), 503), $ctx), '503 should match');
        $this->assertFalse($dec->shouldRetry($this->outcome(false, new \RuntimeException(), 418), $ctx), '418 should not match');
    }

    public function test_on_tokens_decider_matches_family_tokens_4xx_5xx(): void
    {
        // Arrange
        $dec = new OnTokensDecider(['4xx', '5XX']); // case-insensitive
        $ctx = $this->context();

        // Act & Assert
        $this->assertTrue($dec->shouldRetry($this->outcome(false, new \RuntimeException(), 404), $ctx));
        $this->assertTrue($dec->shouldRetry($this->outcome(false, new \RuntimeException(), 500), $ctx));
        $this->assertFalse($dec->shouldRetry($this->outcome(false, new \RuntimeException(), 302), $ctx));
        // status null -> family tokens should not match
        $this->assertFalse($dec->shouldRetry($this->outcome(false, new \RuntimeException(), null), $ctx));
    }

    public function test_on_tokens_decider_matches_named_tags_case_insensitive(): void
    {
        // Arrange
        $dec = new OnTokensDecider(['retry-after', 'TRANSIENT']);
        $ctx = $this->context();

        // Act & Assert
        $this->assertTrue($dec->shouldRetry($this->outcome(false, new \RuntimeException(), null, ['Retry-After']), $ctx));
        $this->assertTrue($dec->shouldRetry($this->outcome(false, new \RuntimeException(), null, ['transient']), $ctx));
        $this->assertFalse($dec->shouldRetry($this->outcome(false, new \RuntimeException(), null, ['permanent']), $ctx));
    }

    public function test_on_tokens_decider_returns_false_on_success_even_if_tokens_match(): void
    {
        // Arrange
        $dec = new OnTokensDecider([429, 'RETRY-AFTER', '5xx']);
        $ctx = $this->context();

        // Act
        $ans1 = $dec->shouldRetry($this->outcome(true,  null, 429, ['retry-after']), $ctx);
        $ans2 = $dec->shouldRetry($this->outcome(true,  null, 500, []), $ctx);

        // Assert
        $this->assertFalse($ans1);
        $this->assertFalse($ans2);
    }

    public function test_on_tokens_decider_set_tokens_normalizes_and_get_tokens(): void
    {
        // Arrange
        $dec = new OnTokensDecider(['retry-after', 429, '5xx']);
        $dec->setTokens(['Transient', '4XX', 503, 'retry-after']);
        $tokens = $dec->getTokens();

        // Assert
        // Ints preserved, strings uppercased & deduped order preserved per implementation
        $this->assertSame(['TRANSIENT', '4XX', 503, 'RETRY-AFTER'], $tokens);
    }
}
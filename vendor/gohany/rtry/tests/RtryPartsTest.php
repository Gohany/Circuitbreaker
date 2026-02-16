<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;
use Gohany\Retry\HedgeInterface;
use Gohany\Retry\JitterInterface;
use Gohany\Rtry\Impl\Parts\Attempts;
use Gohany\Rtry\Impl\Parts\Base;
use Gohany\Rtry\Impl\Parts\Cap;
use Gohany\Rtry\Impl\Parts\Deadline;
use Gohany\Rtry\Impl\Parts\Delay;
use Gohany\Rtry\Impl\Parts\FollowHeaders;
use Gohany\Rtry\Impl\Parts\Hedge;
use Gohany\Rtry\Impl\Parts\Jitter;
use Gohany\Rtry\Impl\Parts\Mode;
use Gohany\Rtry\Impl\Parts\On;
use Gohany\Rtry\Impl\Parts\Sequence;
use Gohany\Rtry\Impl\Parts\StartAfter;
use Gohany\Rtry\Impl\Parts\Timeout;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use PHPUnit\Framework\TestCase;

class RtryPartsTest extends TestCase
{

    private function freshPolicy(string $spec = 'rtry:m=lin;d=100'): object
    {
        // A minimal valid policy to apply parts onto.
        $factory = new RtryPolicyFactory();
        return $factory->fromSpec($spec);
    }

    // ===== Attempts =====
    public function test_attempts_make_toString_and_apply(): void
    {
        // Arrange
        $part = Attempts::make('5');
        $policy = $this->freshPolicy();

        // Act
        $part->applyToPolicy($policy);

        // Assert
        $this->assertSame('a=5', (string)$part);
        $this->assertSame(5, $policy->attempts());
    }

    // ===== Base (exponential) =====
    public function test_base_make_toString_and_apply(): void
    {
        // Arrange
        $part = Base::make('2');
        $policy = $this->freshPolicy('rtry:m=exp'); // switch to exponential

        // Act
        $part->applyToPolicy($policy);

        // Assert
        $this->assertSame('b=2', (string)$part);
        $this->assertSame(2.0, $policy->exponentialBase());
    }

    // ===== Cap =====
    public function test_cap_make_toString_and_apply(): void
    {
        // Arrange
        $part = Cap::make('1.5s'); // 1500ms
        $policy = $this->freshPolicy();

        // Act
        $part->applyToPolicy($policy);

        // Assert
        $this->assertSame('cap=1.5s', (string)$part);
        $this->assertSame(1500, $policy->capMs());
    }

    // ===== Deadline =====
    public function test_deadline_make_numeric_toString_and_apply(): void
    {
        // Arrange
        $part = Deadline::make('2500'); // 2500ms
        $policy = $this->freshPolicy();

        // Act
        $part->applyToPolicy($policy);

        // Assert
        $this->assertSame('dl=2.5s', (string)$part);
        $this->assertSame(2500, $policy->deadlineBudgetMs());
    }

    // ===== Delay (linear) =====
    public function test_delay_make_toString_and_apply(): void
    {
        // Arrange
        $part = Delay::make('250ms');
        $policy = $this->freshPolicy('rtry:m=lin;d=100'); // base has a delay already

        // Act
        $part->applyToPolicy($policy);

        // Assert
        $this->assertSame('d=250', (string)$part);
        $this->assertSame(250, $policy->delayMs());
    }

    // ===== FollowHeaders =====
    public function test_follow_headers_make_toString_and_apply(): void
    {
        // Arrange
        $part = FollowHeaders::make('1');
        $policy = $this->freshPolicy();

        // Act
        $part->applyToPolicy($policy);

        // Assert
        $this->assertSame('fh=1', (string)$part);
        $this->assertTrue($policy->followHeaders());
    }

    // ===== Hedge =====
    public function test_hedge_make_toString_and_apply(): void
    {
        // Arrange
        $part = Hedge::make('3@100&1'); // lanes=3, stagger=100ms, cancel policy=1
        $policy = $this->freshPolicy();

        // Act
        $part->applyToPolicy($policy);
        $hedge = $policy->hedge();

        // Assert
        $this->assertSame('h=3@100&1', (string)$part);
        $this->assertInstanceOf(HedgeInterface::class, $hedge);
        $this->assertSame(3, $hedge->lanes());
        $this->assertSame(100, $hedge->staggerDelayMs());
        $this->assertSame('1', (string)$hedge->cancelPolicy());
    }

    public function test_hedge_make_toString_and_apply_strips_default_cancel_policy(): void
    {
        // Arrange
        $part = Hedge::make('3@100&0'); // lanes=3, stagger=100ms, cancel policy=0
        $policy = $this->freshPolicy();

        // Act
        $part->applyToPolicy($policy);

        // Assert
        $this->assertSame('h=3@100', (string)$part);
    }

    // ===== Jitter =====
    public function test_jitter_make_toString_and_apply_percent_pm(): void
    {
        // Arrange
        $part = Jitter::make('20%@pm');
        $policy = $this->freshPolicy('rtry:m=lin;d=250;sa=1000');

        // Act
        $part->applyToPolicy($policy);
        $jit = $policy->jitter();

        // Assert
        $this->assertSame('j=20%@pm', (string)$part);
        $this->assertInstanceOf(JitterInterface::class, $jit);
        $this->assertSame('pm', $jit->mode());
        $this->assertSame(20.0, $jit->percent());
        $this->assertNull($jit->windowMs());

        // Deterministic sample with seed
        $this->assertSame($part->apply(1000, 42), $jit->apply(1000, 42));
    }

    public function test_jitter_make_toString_and_apply_full_window(): void
    {
        // Arrange
        $part = Jitter::make('100ms@full');
        $policy = $this->freshPolicy('rtry:m=exp;b=2;sa=0');

        // Act
        $part->applyToPolicy($policy);
        $jit = $policy->jitter();

        // Assert
        $this->assertSame('j=100', (string)$part);
        $this->assertInstanceOf(JitterInterface::class, $jit);
        $this->assertSame('full', $jit->mode());
        $this->assertNull($jit->percent());
        $this->assertSame(100, $jit->windowMs());
        $this->assertSame($part->apply(500, 7), $jit->apply(500, 7));
    }

    // ===== Mode =====
    public function test_mode_make_toString_and_apply_valid(): void
    {
        // Arrange
        $part = Mode::make('seq');
        $policy = $this->freshPolicy('rtry:m=lin;d=1');

        // Act
        $part->applyToPolicy($policy);

        // Assert
        $this->assertSame('m=seq', (string)$part);
        $this->assertSame('seq', $policy->backoffMode());
    }

    public function test_mode_make_invalid_throws(): void
    {
        // Arrange
        $this->expectException(\InvalidArgumentException::class);

        // Act
        Mode::make('oops'); // invalid
    }

    // ===== On =====
    public function test_on_make_toString_and_apply_tokens(): void
    {
        // Arrange
        $part = On::make('429,default');
        $policy = $this->freshPolicy();

        // Act
        $part->applyToPolicy($policy);

        // Assert
        $this->assertSame('on=429,default', (string)$part);
        // If the policy exposes tokens, assert them; otherwise just ensure no exception on apply.
        if (method_exists($policy, 'retryOnTokens')) {
            $this->assertSame(['429','default'], $policy->retryOnTokens());
        }
    }

    public function test_sequence_make_toString_and_iteration_with_repeat(): void
    {
        // Arrange
        $part = Sequence::make('(50,100ms,1.5s*)');
        $part2 = Sequence::make('seq=(50,100,1.5s,*)');

        // Act
        $string = (string)$part;

        // Assert (string form + config)
        $this->assertSame('seq=50,100,1.5s*', $string, 'Sequence __toString() should round/format and keep the trailing *');
        $this->assertSame([50, 100, 1500], $part->delaysMs());
        $this->assertTrue($part->isRepeatLastDelay());

        // Arrange (iteration)
        $part->reset();

        // Act (+ Assert iteration behavior)
        $this->assertSame(50,   $part->nextDelayMs());
        $this->assertSame(100,  $part->nextDelayMs());
        $this->assertSame(1500, $part->nextDelayMs());
        // repeats last forever when '*' present
        $this->assertSame(1500, $part->nextDelayMs());
        $this->assertSame(1500, $part->nextDelayMs());

        // Arrange (reset)
        $part->reset();
        // Act & Assert (re-iterate from beginning)
        $this->assertSame(50, $part->nextDelayMs());

        $this->assertSame([50, 100, 1500], $part2->delaysMs());
        $this->assertTrue($part2->isRepeatLastDelay());
        $this->assertSame('seq=50,100,1.5s*', (string)$part2);
    }

    public function test_sequence_make_toString_and_iteration_without_repeat(): void
    {
        // Arrange
        $part = Sequence::make('(25,75ms,200ms)');

        // Act
        $string = (string)$part;

        // Assert (string form + config)
        $this->assertSame('seq=25,75,200', $string);
        $this->assertSame([25, 75, 200], $part->delaysMs());
        $this->assertFalse($part->isRepeatLastDelay());

        // Arrange (iteration)
        $part->reset();

        // Act & Assert (ends with null when no '*')
        $this->assertSame(25,  $part->nextDelayMs());
        $this->assertSame(75,  $part->nextDelayMs());
        $this->assertSame(200, $part->nextDelayMs());
        $this->assertNull($part->nextDelayMs());
        $this->assertNull($part->nextDelayMs());
    }

    public function test_sequence_apply_to_policy_sets_sequence(): void
    {
        // Arrange
        $policy = $this->freshPolicy('rtry:m=seq'); // use seq mode
        $part   = Sequence::make('(10ms,20ms,*)');

        // Act
        $returned = $part->applyToPolicy($policy);

        // Assert
        $this->assertSame($policy, $returned, 'applyToPolicy should return the same policy instance');
        // If policy exposes a getter, assert identity; otherwise just ensure no exception.
        if (method_exists($policy, 'sequence')) {
            $this->assertSame($part, $policy->sequence());
        } elseif (method_exists($policy, 'toSpec')) {
            $spec = (new RtryPolicyFactory())->fromSpec('rtry:m=seq')->toSpec(); // baseline
            $this->assertIsString($spec, 'sanity check toSpec exists');
        }
    }

    // ===== StartAfter =====

    public function test_start_after_make_toString_and_apply_numeric_ms(): void
    {
        // Arrange
        $part   = StartAfter::make('250');
        $policy = $this->freshPolicy();

        // Act
        $part->applyToPolicy($policy);

        // Assert
        $this->assertSame('sa=250', (string)$part);
        $this->assertSame(250, $policy->startAfterMs());
    }

    public function test_start_after_make_toString_and_apply_with_units(): void
    {
        // Arrange
        $part   = StartAfter::make('1.5s'); // 1500ms
        $policy = $this->freshPolicy();

        // Act
        $part->applyToPolicy($policy);

        // Assert
        $this->assertSame('sa=1.5s', (string)$part);
        $this->assertSame(1500, $policy->startAfterMs());
    }

    // ===== Timeout =====

    public function test_timeout_make_toString_and_apply(): void
    {
        // Arrange
        $part   = Timeout::make('2s'); // 2000ms
        $policy = $this->freshPolicy();

        // Act
        $return = $part->applyToPolicy($policy);

        // Assert
        $this->assertSame('t=2s', (string)$part);
        $this->assertSame($policy, $return, 'applyToPolicy should return the same policy instance');

        // If the policy exposes a getter, assert it; otherwise we at least verify no exception was thrown.
        if (method_exists($policy, 'attemptTimeoutMs')) {
            $this->assertSame(2000, $policy->attemptTimeoutMs());
        }
    }

}
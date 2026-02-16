<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use PHPUnit\Framework\TestCase;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Retry\JitterInterface;
use Gohany\Retry\HedgeInterface;
use Gohany\Rtry\Impl\Parts\Jitter;
use Gohany\Rtry\Impl\Parts\Hedge;

final class RtryPolicyFactoryTest extends TestCase
{
    public function test_exp_with_base_commons(): void
    {
        // Arrange
        $spec = 'rtry:m=exp;b=2;a=5;dl=5000;sa=250;fh=0;cap=4000';
        $expectedSpec = 'rtry:m=exp;b=2;a=5;dl=5s;sa=250;fh=0;cap=4s';
        $factory = new RtryPolicyFactory();

        // Act
        $policy = $factory->fromSpec($spec);
        $round  = $factory->toSpec();

        // Assert
        $this->assertSame('exp', $policy->backoffMode());
        $this->assertSame(2.0, $policy->exponentialBase());
        $this->assertSame(5, $policy->attempts());
        $this->assertSame(5000, $policy->deadlineBudgetMs());
        $this->assertSame(250, $policy->startAfterMs());
        $this->assertSame(false, $policy->followHeaders());
        $this->assertSame(4000, $policy->capMs());

        $this->assertStringStartsWith('rtry:', $round);
        $this->assertSame($expectedSpec, $round);
    }

    public function test_exp_with_base_commons_jitter_and_hedge(): void
    {
        // Arrange
        $spec = 'rtry:m=exp;b=2;a=5;dl=5000;sa=250;cap=4000;j=20%@pm;h=3@100&1';
        $expJ = Jitter::make('20%@pm');
        $expH = Hedge::make('3@100&1');
        $factory = new RtryPolicyFactory();

        // Act
        $policy = $factory->fromSpec($spec);
        $round  = $factory->toSpec();
        $policy->setSeed(424242);

        // Assert
        $this->assertSame('exp', $policy->backoffMode());
        $this->assertSame(2.0, $policy->exponentialBase());
        $this->assertSame(5, $policy->attempts());
        $this->assertSame(5000, $policy->deadlineBudgetMs());
        $this->assertSame(297, $policy->startAfterMs());
        $this->assertSame(true, $policy->followHeaders());
        $this->assertSame(4000, $policy->capMs());

        $jitter = $policy->jitter();
        $this->assertInstanceOf(JitterInterface::class, $jitter);
        $this->assertSame($expJ->mode(), $jitter->mode());
        $this->assertSame($expJ->percent(), $jitter->percent());
        $this->assertSame($expJ->windowMs(), $jitter->windowMs());
        $this->assertSame($expJ->apply(1000, 424242), $jitter->apply(1000, 424242), 'jitter apply() must be deterministic with seed');

        $hedge = $policy->hedge();
        $this->assertInstanceOf(HedgeInterface::class, $hedge);
        $this->assertSame($expH->lanes(), $hedge->lanes());
        $this->assertSame($expH->staggerDelayMs(), $hedge->staggerDelayMs());
        $this->assertSame($expH->cancelPolicy(), $hedge->cancelPolicy());

        $this->assertStringStartsWith('rtry:', $round);
        $this->assertStringContainsString((string)$expJ, $round);
        $this->assertStringContainsString((string)$expH, $round);
    }

    public function test_lin_with_delay_commons(): void
    {
        // Arrange
        $spec = 'rtry:m=lin;d=100;a=3;dl=8000;sa=50;fh=0;cap=12000';
        $expectedSpec = 'rtry:m=lin;d=100;a=3;dl=8s;sa=50;fh=0;cap=12s';
        $factory = new RtryPolicyFactory();

        // Act
        $policy = $factory->fromSpec($spec);
        $round  = $factory->toSpec();

        // Assert
        $this->assertSame('lin', $policy->backoffMode());
        $this->assertSame(100, $policy->delayMs());
        $this->assertSame(3, $policy->attempts());
        $this->assertSame(8000, $policy->deadlineBudgetMs());
        $this->assertSame(50, $policy->startAfterMs());
        $this->assertSame(false, $policy->followHeaders());
        $this->assertSame(12000, $policy->capMs());

        $this->assertStringStartsWith('rtry:', $round);
        $this->assertSame($expectedSpec, $round);
    }

    public function test_lin_with_delay_commons_jitter_and_hedge(): void
    {
        // Arrange
        $spec = 'rtry:m=lin;d=100;a=3;dl=8000;sa=50;fh=0;cap=12000;j=100ms@full;h=2@1m';
        $expJ = Jitter::make('100ms@full');
        $expH = Hedge::make('2@1m');
        $factory = new RtryPolicyFactory();

        // Act
        $policy = $factory->fromSpec($spec);
        $policy->setSeed(424242);
        $round  = $factory->toSpec();

        // Assert
        $this->assertSame('lin', $policy->backoffMode());
        $this->assertSame(100, $policy->delayMs());
        $this->assertSame(3, $policy->attempts());
        $this->assertSame(8000, $policy->deadlineBudgetMs());
        $this->assertSame(100, $policy->startAfterMs());
        $this->assertSame(false, $policy->followHeaders());
        $this->assertSame(12000, $policy->capMs());

        $jit = $policy->jitter();
        $this->assertInstanceOf(JitterInterface::class, $jit);
        $this->assertSame($expJ->mode(), $jit->mode());
        $this->assertSame($expJ->percent(), $jit->percent());
        $this->assertSame($expJ->windowMs(), $jit->windowMs());
        $this->assertSame($expJ->apply(500, 7), $jit->apply(500, 7));

        $hdg = $policy->hedge();
        $this->assertInstanceOf(HedgeInterface::class, $hdg);
        $this->assertSame($expH->lanes(), $hdg->lanes());
        $this->assertSame($expH->staggerDelayMs(), $hdg->staggerDelayMs());
        $this->assertSame($expH->cancelPolicy(), $hdg->cancelPolicy());

        $this->assertStringStartsWith('rtry:', $round);
        $this->assertStringContainsString((string)$expJ, $round);
        $this->assertStringContainsString((string)$expH, $round);
    }

    public function test_seq_with_list(): void
    {
        // Arrange
        $spec = 'rtry:m=seq;seq=(50,100,200,*);a=4;dl=9000;sa=10;fh=0;cap=20000';
        $expectedSpec = 'rtry:m=seq;seq=50,100,200*;a=4;dl=9s;sa=10;fh=0;cap=20s';
        $factory = new RtryPolicyFactory();

        // Act
        $policy = $factory->fromSpec($spec);
        $round  = $factory->toSpec();

        // Assert
        $this->assertSame('seq', $policy->backoffMode());
        $this->assertStringStartsWith('rtry:m=seq;seq=', $round, 'prefix should preserve m=seq followed by seq=');

        // Common knobs
        $this->assertSame(4, $policy->attempts());
        $this->assertSame(9000, $policy->deadlineBudgetMs());
        $this->assertSame(10, $policy->startAfterMs());
        $this->assertSame(false, $policy->followHeaders());
        $this->assertSame(20000, $policy->capMs());
        $this->assertSame(50, $policy->nextDelayMs(1));
        $this->assertSame(100, $policy->nextDelayMs(2));
        $this->assertSame(200, $policy->nextDelayMs(3));
        $this->assertSame(200, $policy->nextDelayMs(4));
        $this->assertSame(200, $policy->nextDelayMs(5));

        $this->assertSame($expectedSpec, $round);
    }

    public function test_seq_with_list_plus_jitter_and_hedge_roundtrips(): void
    {
        // Arrange
        $spec = 'rtry:m=seq;seq=(50,100,200,*);a=4;dl=9000;sa=10;fh=0;cap=20000;j=1.5s@pm;h=2@10s';
        $expJ = Jitter::make('1.5s@pm');
        $expH = Hedge::make('2@10s');
        $factory = new RtryPolicyFactory();

        // Act
        $policy = $factory->fromSpec($spec);
        $policy->setSeed(424242);
        $round  = $factory->toSpec();

        // Assert
        $this->assertSame('seq', $policy->backoffMode());
        $this->assertStringStartsWith('rtry:m=seq;seq=', $round, 'prefix should preserve m=seq followed by seq=');

        // Common knobs
        $this->assertSame(4, $policy->attempts());
        $this->assertSame(9000, $policy->deadlineBudgetMs());
        $this->assertSame(1130, $policy->startAfterMs());
        $this->assertSame(false, $policy->followHeaders());
        $this->assertSame(20000, $policy->capMs());
        $this->assertSame(0, $policy->nextDelayMs(1));
        $this->assertSame(0, $policy->nextDelayMs(2));
        $this->assertSame(28, $policy->nextDelayMs(3));
        $this->assertSame(28, $policy->nextDelayMs(4));
        $this->assertSame(28, $policy->nextDelayMs(5));

        // Jitter/Hedge equality to parts
        $jitter = $policy->jitter();
        $hedge = $policy->hedge();
        $this->assertInstanceOf(JitterInterface::class, $jitter);
        $this->assertInstanceOf(HedgeInterface::class, $hedge);
        $this->assertSame($expJ->mode(), $jitter->mode());
        $this->assertSame($expJ->percent(), $jitter->percent());
        $this->assertSame($expJ->windowMs(), $jitter->windowMs());
        $this->assertSame($expH->lanes(), $hedge->lanes());
        $this->assertSame($expH->staggerDelayMs(), $hedge->staggerDelayMs());
        $this->assertSame($expH->cancelPolicy(), $hedge->cancelPolicy());

        $this->assertStringContainsString((string)$expJ, $round);
        $this->assertStringContainsString((string)$expH, $round);
    }

    public function test_accepts_prefix_and_no_prefix_equivalently(): void
    {
        // Arrange
        $raw = 'm=lin;d=250;a=2;j=50@pm;h=2@100';
        $specWith = 'rtry:' . $raw;
        $factory = new RtryPolicyFactory();

        // Act
        $p1 = $factory->fromSpec($specWith);
        $r1 = $factory->toSpec();

        $factory2 = new RtryPolicyFactory();
        $p2 = $factory2->fromSpec($raw);
        $r2 = $factory2->toSpec();

        // Assert (compare key knobs)
        $this->assertSame('lin', $p1->backoffMode());
        $this->assertSame('lin', $p2->backoffMode());
        $this->assertSame(250, $p1->delayMs());
        $this->assertSame(250, $p2->delayMs());
        $this->assertSame(2, $p1->attempts());
        $this->assertSame(2, $p2->attempts());

        // Jitter/Hedge shape parity
        $this->assertSame($p1->jitter()->mode(), $p2->jitter()->mode());
        $this->assertSame($p1->hedge()->lanes(), $p2->hedge()->lanes());

        $this->assertStringStartsWith('rtry:', $r1);
        $this->assertStringStartsWith('rtry:', $r2);

        $this->assertSame($r1, $r2, 'roundtripped specs must be identical');
    }

    public function test_unknown_key_throws(): void
    {
        // Arrange
        $spec = 'rtry:m=exp;x=5';

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        (new RtryPolicyFactory())->fromSpec($spec);
    }

    public function test_empty_spec_throws(): void
    {
        // Arrange
        $spec = 'rtry:';

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        (new RtryPolicyFactory())->fromSpec($spec);
    }
}
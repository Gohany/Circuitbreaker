<?php

declare(strict_types=1);

namespace tests\Resilience;

use Gohany\Circuitbreaker\Resilience\Context;
use Gohany\Circuitbreaker\Resilience\ResilienceMiddlewareInterface;
use Gohany\Circuitbreaker\Resilience\ResiliencePipeline;
use PHPUnit\Framework\TestCase;

final class ResiliencePipelineTest extends TestCase
{
    public function testMiddlewareOrderIsOuterToInner(): void
    {
        $events = [];

        $mwA = new class($events) implements ResilienceMiddlewareInterface {
            /** @var array<int,string> */
            private array $events;
            public function __construct(array &$events) { $this->events = &$events; }
            public function handle(Context $ctx, callable $next)
            {
                $this->events[] = 'A:before';
                $res = $next($ctx);
                $this->events[] = 'A:after';
                return $res;
            }
        };

        $mwB = new class($events) implements ResilienceMiddlewareInterface {
            /** @var array<int,string> */
            private array $events;
            public function __construct(array &$events) { $this->events = &$events; }
            public function handle(Context $ctx, callable $next)
            {
                $this->events[] = 'B:before';
                $res = $next($ctx);
                $this->events[] = 'B:after';
                return $res;
            }
        };

        $pipeline = new ResiliencePipeline([$mwA, $mwB]);
        $ctx = new Context('op', 'default');

        $out = $pipeline->execute($ctx, function () use (&$events): string {
            $events[] = 'core';
            return 'ok';
        });

        $this->assertSame('ok', $out);
        $this->assertSame(['A:before', 'B:before', 'core', 'B:after', 'A:after'], $events);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $mw = new class implements ResilienceMiddlewareInterface {
            public function handle(Context $ctx, callable $next)
            {
                return 'short';
            }
        };

        $pipeline = new ResiliencePipeline([$mw]);
        $ctx = new Context('op', 'default');

        $called = 0;
        $out = $pipeline->execute($ctx, function () use (&$called): string {
            $called++;
            return 'core';
        });

        $this->assertSame('short', $out);
        $this->assertSame(0, $called);
    }
}

<?php

declare(strict_types=1);

namespace tests\Resilience;

use Gohany\Circuitbreaker\Resilience\Context;
use Gohany\Circuitbreaker\Resilience\RetryConfig;
use Gohany\Circuitbreaker\Resilience\RetryMiddleware;
use PHPUnit\Framework\TestCase;

final class RetryMiddlewareTest extends TestCase
{
    public function testMapsRetryConfigToRtrySpecAndRetries(): void
    {
        $cfg = new RetryConfig();
        $cfg->maxAttempts = 2;
        $cfg->baseDelayMs = 0;

        $mw = new RetryMiddleware($cfg);
        $ctx = new Context('op', 'default');

        $calls = 0;
        $out = $mw->handle($ctx, function () use (&$calls): string {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('first');
            }
            return 'ok';
        });

        $this->assertSame('ok', $out);
        $this->assertSame(2, $calls);
    }

    public function testAcceptsSpecString(): void
    {
        $mw = new RetryMiddleware('rtry:a=1;d=0ms;on=default');
        $ctx = new Context('op', 'default');

        $calls = 0;
        $out = $mw->handle($ctx, function () use (&$calls): string {
            $calls++;
            return 'ok';
        });

        $this->assertSame('ok', $out);
        $this->assertSame(1, $calls);
    }
}

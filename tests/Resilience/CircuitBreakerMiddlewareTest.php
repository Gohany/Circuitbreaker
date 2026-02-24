<?php

declare(strict_types=1);

namespace tests\Resilience;

use Gohany\Circuitbreaker\Contracts\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Resilience\CircuitBreakerMiddleware;
use Gohany\Circuitbreaker\Resilience\Context;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerMiddlewareTest extends TestCase
{
    public function testRecordsSuccessAndReturnsResult(): void
    {
        $calls = [];
        $circuit = new class($calls) implements CircuitBreakerInterface {
            /** @var array<int,string> */
            private array $calls;
            public function __construct(array &$calls) { $this->calls = &$calls; }
            public function acquirePermission(string $operation): void { $this->calls[] = 'acquire:' . $operation; }
            public function recordSuccess(string $operation, ?float $durationSeconds = null): void { $this->calls[] = 'success:' . $operation; }
            public function recordFailure(string $operation, \Throwable $error, ?float $durationSeconds = null): void { $this->calls[] = 'failure:' . $operation; }
            public function getState(string $operation): string { return 'closed'; }
        };

        $mw = new CircuitBreakerMiddleware($circuit);
        $ctx = new Context('op1', 'default');

        $out = $mw->handle($ctx, function (Context $ctx2): string {
            $this->assertSame('op1', $ctx2->getOperation());
            return 'ok';
        });

        $this->assertSame('ok', $out);
        $this->assertSame(['acquire:op1', 'success:op1'], $calls);
    }

    public function testRecordsFailureAndRethrows(): void
    {
        $calls = [];
        $circuit = new class($calls) implements CircuitBreakerInterface {
            /** @var array<int,string> */
            private array $calls;
            public function __construct(array &$calls) { $this->calls = &$calls; }
            public function acquirePermission(string $operation): void { $this->calls[] = 'acquire:' . $operation; }
            public function recordSuccess(string $operation, ?float $durationSeconds = null): void { $this->calls[] = 'success:' . $operation; }
            public function recordFailure(string $operation, \Throwable $error, ?float $durationSeconds = null): void { $this->calls[] = 'failure:' . $operation . ':' . get_class($error); }
            public function getState(string $operation): string { return 'closed'; }
        };

        $mw = new CircuitBreakerMiddleware($circuit);
        $ctx = new Context('op1', 'default');

        try {
            $mw->handle($ctx, function (): void {
                throw new \RuntimeException('nope');
            });
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('nope', $e->getMessage());
        }

        $this->assertSame(['acquire:op1', 'failure:op1:RuntimeException'], $calls);
    }
}

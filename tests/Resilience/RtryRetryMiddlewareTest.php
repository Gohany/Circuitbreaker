<?php

declare(strict_types=1);

namespace tests\Resilience;

use Gohany\Circuitbreaker\Observability\EmitterInterface;
use Gohany\Circuitbreaker\Resilience\Context;
use Gohany\Circuitbreaker\Resilience\RtryRetryMiddleware;
use PHPUnit\Framework\TestCase;

final class RtryRetryMiddlewareTest extends TestCase
{
    public function testRetriesAndReturnsResult(): void
    {
        $emitter = new CapturingEmitter();
        $mw = new RtryRetryMiddleware('rtry:attempts=3;delay=0ms;on=default', $emitter);

        $ctx = new Context('op-a', 'lane-a');

        $calls = 0;
        $res = $mw->handle($ctx, function (Context $ctx) use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new \RuntimeException('transient');
            }
            return 'ok';
        });

        $this->assertSame('ok', $res);
        $this->assertSame(3, $calls);

        $events = $emitter->events();
        $attempts = array_values(array_filter($events, static function (array $e): bool {
            return $e['name'] === 'retry.attempt';
        }));
        $giveUps = array_values(array_filter($events, static function (array $e): bool {
            return $e['name'] === 'retry.give_up';
        }));

        // In rtry, between-attempts hook fires after a failed attempt, so it should run twice here.
        $this->assertCount(2, $attempts);
        $this->assertCount(0, $giveUps);

        $this->assertSame('op-a', $attempts[0]['context']['operation']);
        $this->assertSame('lane-a', $attempts[0]['context']['lane']);
        $this->assertSame(\RuntimeException::class, $attempts[0]['context']['error_class']);
    }

    public function testGiveUpEmitsAndRethrows(): void
    {
        $emitter = new CapturingEmitter();
        $mw = new RtryRetryMiddleware('rtry:attempts=2;delay=0ms;on=default', $emitter);
        $ctx = new Context('op-b', 'lane-b');

        $this->expectException(\RuntimeException::class);

        try {
            $mw->handle($ctx, function (Context $ctx) {
                throw new \RuntimeException('nope');
            });
        } finally {
            $events = $emitter->events();
            $giveUps = array_values(array_filter($events, static function (array $e): bool {
                return $e['name'] === 'retry.give_up';
            }));
            $this->assertCount(1, $giveUps);
            $this->assertSame('op-b', $giveUps[0]['context']['operation']);
            $this->assertSame('lane-b', $giveUps[0]['context']['lane']);
            $this->assertSame(\RuntimeException::class, $giveUps[0]['context']['error_class']);
        }
    }
}

final class CapturingEmitter implements EmitterInterface
{
    /** @var array<int,array{name:string,context:array<string,mixed>}> */
    private $events = [];

    public function emit(string $eventName, array $context = []): void
    {
        $this->events[] = ['name' => $eventName, 'context' => $context];
    }

    /**
     * @return array<int,array{name:string,context:array<string,mixed>}>
     */
    public function events(): array
    {
        return $this->events;
    }
}

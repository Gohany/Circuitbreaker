<?php

declare(strict_types=1);

namespace tests;

use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitDecision;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Defaults\Http\DefaultMultiHttpCircuitsBuilder;
use Gohany\Circuitbreaker\Defaults\Http\HttpCircuitDefinition;
use Gohany\Circuitbreaker\Defaults\Http\MultiCircuitBreakingPsr18Client;
use Gohany\Circuitbreaker\Exception\CircuitDeniedException;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\OutcomeClassifierInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\AbstractLogger;

final class MultiCircuitBreakingPsr18ClientFailureHandlingTest extends TestCase
{
    public function testRecordingFailureDoesNotPreventOtherRecordingsAndStillReturnsWhenCircuitsAllow(): void
    {
        $inner = $this->createMock(ClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $inner->method('sendRequest')->willReturn($response);

        $calls = [];

        $breaker1 = new class($calls) implements CircuitBreakerInterface {
            public array $calls;
            public function __construct(array &$calls) { $this->calls = &$calls; }
            public function decide(CircuitKey $key, CircuitContext $context): CircuitDecision
            {
                $this->calls[] = 'b1.decide:' . $key->name;
                return new CircuitDecision(true, 'ok', null, []);
            }
            public function execute(CircuitKey $key, CircuitContext $context, callable $operation) { return $operation(); }
            public function recordOutcome(CircuitKey $key, CircuitContext $context, CircuitOutcome $outcome): void
            {
                $this->calls[] = 'b1.record:' . $key->name;
                throw new \RuntimeException('store down');
            }
        };

        $breaker2 = new class($calls) implements CircuitBreakerInterface {
            public array $calls;
            public function __construct(array &$calls) { $this->calls = &$calls; }
            public function decide(CircuitKey $key, CircuitContext $context): CircuitDecision
            {
                $this->calls[] = 'b2.decide:' . $key->name;
                return new CircuitDecision(true, 'ok', null, []);
            }
            public function execute(CircuitKey $key, CircuitContext $context, callable $operation) { return $operation(); }
            public function recordOutcome(CircuitKey $key, CircuitContext $context, CircuitOutcome $outcome): void
            {
                $this->calls[] = 'b2.record:' . $key->name;
            }
        };

        $classifier = new class implements OutcomeClassifierInterface {
            public function classify($result, $error, array $context = []): CircuitOutcome
            {
                return new CircuitOutcome(true, [], null, [], 0);
            }
        };

        $logger = new TestCriticalLogger();

        $client = new MultiCircuitBreakingPsr18Client(
            $inner,
            [
                new HttpCircuitDefinition($breaker1, $classifier, 'c1', false),
                new HttpCircuitDefinition($breaker2, $classifier, 'c2', false),
            ],
            new DefaultMultiHttpCircuitsBuilder(),
            $logger
        );

        $request = $this->createMock(RequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn('example.com');
        $request->method('getUri')->willReturn($uri);
        $request->method('hasHeader')->with('X-Tenant-Id')->willReturn(false);

        $res = $client->sendRequest($request);
        $this->assertSame($response, $res);

        // Ensure both circuits were attempted in order for precheck and record.
        $this->assertSame([
            'b1.decide:c1:example.com',
            'b2.decide:c2:example.com',
            'b1.record:c1:example.com',
            'b2.record:c2:example.com',
            'b1.decide:c1:example.com',
            'b2.decide:c2:example.com',
        ], $calls);

        $this->assertSame(1, $logger->criticalCalls);
    }

    public function testIfAnyCircuitDeniesAfterRecordingClientThrowsCircuitDeniedException(): void
    {
        $inner = $this->createMock(ClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $inner->method('sendRequest')->willReturn($response);

        $breaker = $this->createMock(CircuitBreakerInterface::class);
        $breaker->expects($this->exactly(2))
            ->method('decide')
            ->willReturnOnConsecutiveCalls(
                new CircuitDecision(true, 'ok', null, []),
                new CircuitDecision(false, 'open', 123, [])
            );

        $breaker->expects($this->once())
            ->method('recordOutcome');

        $classifier = new class implements OutcomeClassifierInterface {
            public function classify($result, $error, array $context = []): CircuitOutcome
            {
                return new CircuitOutcome(true, [], null, [], 0);
            }
        };

        $client = new MultiCircuitBreakingPsr18Client(
            $inner,
            [new HttpCircuitDefinition($breaker, $classifier, 'c1', false)],
            new DefaultMultiHttpCircuitsBuilder()
        );

        $request = $this->createMock(RequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn('example.com');
        $request->method('getUri')->willReturn($uri);
        $request->method('hasHeader')->with('X-Tenant-Id')->willReturn(false);

        $this->expectException(CircuitDeniedException::class);
        $this->expectExceptionMessage('open');

        $client->sendRequest($request);
    }
}

final class TestCriticalLogger extends AbstractLogger
{
    public int $criticalCalls = 0;

    public function log($level, $message, array $context = []): void
    {
        if ((string) $level === 'critical') {
            $this->criticalCalls++;
        }
    }
}

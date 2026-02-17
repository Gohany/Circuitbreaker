<?php

declare(strict_types=1);

namespace tests;

use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitDecision;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerRequestInterface;
use Gohany\Circuitbreaker\Defaults\Http\HttpCircuitDefinition;
use Gohany\Circuitbreaker\Defaults\Http\DefaultHttpCircuitBuilder;
use Gohany\Circuitbreaker\Defaults\Http\DefaultMultiHttpCircuitsBuilder;
use Gohany\Circuitbreaker\Defaults\Http\DefaultMultiHttpCircuitBuilder;
use Gohany\Circuitbreaker\Defaults\Http\HttpCircuitBuilderInterface;
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakingPsr18Client;
use Gohany\Circuitbreaker\Defaults\Http\MultiCircuitBreakingPsr18Client;
use Gohany\Circuitbreaker\Defaults\Rtry\SaneRetryPolicies;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\OutcomeClassifierInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

final class DefaultsTest extends TestCase
{
    public function testPsr18ClientDecorator(): void
    {
        $inner = $this->createMock(ClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $inner->method('sendRequest')->willReturn($response);

        $breaker = $this->createMock(CircuitBreakerInterface::class);
        $breaker->expects($this->once())
            ->method('execute')
            ->with(
                $this->callback(fn(CircuitKey $key) => $key->name === 'http:example.com'),
                $this->callback(fn(CircuitContext $ctx) => $ctx->tenantId === 't1'),
                $this->isType('callable')
            )
            ->willReturnCallback(fn($key, $ctx, $op) => $op());

        $client = new CircuitBreakingPsr18Client($inner, $breaker);

        $request = $this->createMock(RequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn('example.com');
        $request->method('getUri')->willReturn($uri);
        $request->method('hasHeader')->with('X-Tenant-Id')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Tenant-Id')->willReturn('t1');

        $res = $client->sendRequest($request);
        $this->assertSame($response, $res);
    }

    public function testPsr18ClientWithCustomBuilder(): void
    {
        $inner = $this->createMock(ClientInterface::class);
        $breaker = $this->createMock(CircuitBreakerInterface::class);
        $builder = $this->createMock(HttpCircuitBuilderInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $request = $this->createMock(RequestInterface::class);
        $customKey = new CircuitKey('custom');
        $customCtx = new CircuitContext('custom_tenant');

        $builder->method('buildKey')->willReturn($customKey);
        $builder->method('buildContext')->willReturn($customCtx);

        $breaker->expects($this->once())
            ->method('execute')
            ->with($customKey, $customCtx)
            ->willReturn($response);

        $client = new CircuitBreakingPsr18Client($inner, $breaker, 'http', $builder);
        $res = $client->sendRequest($request);

        $this->assertSame($response, $res);
    }

    public function testPsr18ClientWithCircuitBreakerRequestInterface(): void
    {
        $inner = $this->createMock(ClientInterface::class);
        $breaker = $this->createMock(CircuitBreakerInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $customKey = new CircuitKey('from_request');
        $customCtx = new CircuitContext('from_request_tenant');

        $request = $this->createMock(CircuitBreakerRequestInterface::class);
        $request->method('getCircuitKey')->willReturn($customKey);
        $request->method('getCircuitContext')->willReturn($customCtx);

        $breaker->expects($this->once())
            ->method('execute')
            ->with($customKey, $customCtx)
            ->willReturn($response);

        $client = new CircuitBreakingPsr18Client($inner, $breaker);
        $res = $client->sendRequest($request);

        $this->assertSame($response, $res);
    }

    public function testMultiCircuitPsr18ClientDecoratorPrechecksAndRecordsSecondaryOutcome(): void
    {
        $inner = $this->createMock(ClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $inner->method('sendRequest')->willReturn($response);

        $primaryBreaker = $this->createMock(CircuitBreakerInterface::class);
        $primaryBreaker->expects($this->exactly(2))
            ->method('decide')
            ->with(
                $this->callback(fn(CircuitKey $key) => $key->name === 'http:example.com'),
                $this->callback(fn(CircuitContext $ctx) => $ctx->tenantId === 't1')
            )
            ->willReturn(new CircuitDecision(true, 'ok', null, []));

        $primaryBreaker->expects($this->once())
            ->method('recordOutcome')
            ->with(
                $this->callback(fn(CircuitKey $key) => $key->name === 'http:example.com'),
                $this->callback(fn(CircuitContext $ctx) => $ctx->tenantId === 't1'),
                $this->callback(fn(CircuitOutcome $o) => $o->success === true)
            );

        $secondaryBreaker = $this->createMock(CircuitBreakerInterface::class);
        $secondaryBreaker->expects($this->exactly(2))
            ->method('decide')
            ->with(
                $this->callback(fn(CircuitKey $key) => $key->name === 'http_fraud:t1'),
                $this->callback(fn(CircuitContext $ctx) => $ctx->tenantId === 't1')
            )
            ->willReturn(new CircuitDecision(true, 'ok', null, []));

        $secondaryBreaker->expects($this->once())
            ->method('recordOutcome')
            ->with(
                $this->callback(fn(CircuitKey $key) => $key->name === 'http_fraud:t1'),
                $this->callback(fn(CircuitContext $ctx) => $ctx->tenantId === 't1'),
                $this->callback(fn(CircuitOutcome $o) => $o->success === true)
            );

        $primaryClassifier = $this->createMock(OutcomeClassifierInterface::class);
        $primaryClassifier->expects($this->once())
            ->method('classify')
            ->with($response, null, $this->isType('array'))
            ->willReturn(new CircuitOutcome(true, [], null, [], 0));

        $secondaryClassifier = $this->createMock(OutcomeClassifierInterface::class);
        $secondaryClassifier->expects($this->once())
            ->method('classify')
            ->with($response, null, $this->isType('array'))
            ->willReturn(new CircuitOutcome(true, [], null, [], 0));

        $request = $this->createMock(RequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn('example.com');
        $request->method('getUri')->willReturn($uri);
        $request->method('hasHeader')->with('X-Tenant-Id')->willReturn(true);
        $request->method('getHeaderLine')->with('X-Tenant-Id')->willReturn('t1');

        $client = new MultiCircuitBreakingPsr18Client(
            $inner,
            [
                new HttpCircuitDefinition($primaryBreaker, $primaryClassifier, 'http', false),
                new HttpCircuitDefinition($secondaryBreaker, $secondaryClassifier, 'http_fraud', true),
            ],
            new DefaultMultiHttpCircuitsBuilder()
        );

        $res = $client->sendRequest($request);
        $this->assertSame($response, $res);
    }

    public function testMultiCircuitPsr18ClientSkipsSecondaryWhenNoTenantId(): void
    {
        $inner = $this->createMock(ClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $inner->method('sendRequest')->willReturn($response);

        $primaryBreaker = $this->createMock(CircuitBreakerInterface::class);
        $primaryBreaker->expects($this->exactly(2))
            ->method('decide')
            ->willReturn(new CircuitDecision(true, 'ok', null, []));

        $primaryBreaker->expects($this->once())
            ->method('recordOutcome')
            ->with(
                $this->callback(fn(CircuitKey $key) => $key->name === 'http:example.com'),
                $this->isInstanceOf(CircuitContext::class),
                $this->isInstanceOf(CircuitOutcome::class)
            );

        $secondaryBreaker = $this->createMock(CircuitBreakerInterface::class);
        $secondaryBreaker->expects($this->never())->method('decide');
        $secondaryBreaker->expects($this->never())->method('recordOutcome');

        $primaryClassifier = $this->createMock(OutcomeClassifierInterface::class);
        $primaryClassifier->expects($this->once())->method('classify')
            ->willReturn(new CircuitOutcome(true, [], null, [], 0));

        $secondaryClassifier = $this->createMock(OutcomeClassifierInterface::class);
        $secondaryClassifier->expects($this->never())->method('classify');

        $request = $this->createMock(RequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn('example.com');
        $request->method('getUri')->willReturn($uri);
        $request->method('hasHeader')->with('X-Tenant-Id')->willReturn(false);

        $client = new MultiCircuitBreakingPsr18Client(
            $inner,
            [
                new HttpCircuitDefinition($primaryBreaker, $primaryClassifier, 'http', false),
                new HttpCircuitDefinition($secondaryBreaker, $secondaryClassifier, 'http_fraud', true),
            ],
            new DefaultMultiHttpCircuitsBuilder()
        );

        $res = $client->sendRequest($request);
        $this->assertSame($response, $res);
    }

    public function testSaneRetryPolicies(): void
    {
        $default = SaneRetryPolicies::defaultHttp();
        $this->assertSame(3, $default->attempts());
        // Seed to get deterministic jitter for startAfterMs, or check nominal value
        $default->setSeed(123);
        $this->assertGreaterThanOrEqual(0, $default->startAfterMs());
        $this->assertLessThanOrEqual(100, $default->startAfterMs());
        
        $conservative = SaneRetryPolicies::conservativeWrite();
        $this->assertSame(2, $conservative->attempts());
        $this->assertSame(200, $conservative->startAfterMs());
    }
}

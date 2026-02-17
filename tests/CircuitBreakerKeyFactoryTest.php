<?php

declare(strict_types=1);

namespace tests;

use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerKeyFactory;
use Gohany\Circuitbreaker\Defaults\Http\Pieces\HeaderDimensionKeyPiece;
use Gohany\Circuitbreaker\Defaults\Http\Pieces\HostKeyPiece;
use Gohany\Circuitbreaker\Defaults\Http\Pieces\PathSectionDimensionKeyPiece;
use Gohany\Circuitbreaker\Defaults\Http\Pieces\ValueDimensionKeyPiece;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

final class CircuitBreakerKeyFactoryTest extends TestCase
{
    public function testBuildIsOrderIndependent(): void
    {
        $req = $this->makeRequest('Example.COM', '/v1/charges/123', [
            'X-Tenant-Id' => 't1',
            'X-Provider-Id' => 'p1',
        ]);

        $a = new CircuitBreakerKeyFactory('svc', [
            new HostKeyPiece(),
            new PathSectionDimensionKeyPiece(2, 'path_section'),
            new HeaderDimensionKeyPiece('http.tenant', 'X-Tenant-Id', 'tenant'),
            new HeaderDimensionKeyPiece('http.provider', 'X-Provider-Id', 'provider'),
        ]);

        $b = new CircuitBreakerKeyFactory('svc', [
            new HeaderDimensionKeyPiece('http.provider', 'X-Provider-Id', 'provider'),
            new PathSectionDimensionKeyPiece(2, 'path_section'),
            new HeaderDimensionKeyPiece('http.tenant', 'X-Tenant-Id', 'tenant'),
            new HostKeyPiece(),
        ]);

        $ka = $a->build($req);
        $kb = $b->build($req);

        $this->assertSame($ka->name, $kb->name);
        $this->assertSame($ka->dimensions, $kb->dimensions);

        $this->assertSame('svc:example.com', $ka->name);
        $this->assertSame([
            'path_section' => 'v1/charges',
            'provider' => 'p1',
            'tenant' => 't1',
        ], $ka->dimensions);
    }

    public function testHostPieceUsesUnknownWhenHostMissing(): void
    {
        $req = $this->makeRequest('', '/', []);
        $factory = new CircuitBreakerKeyFactory('svc', [new HostKeyPiece()]);

        $key = $factory->build($req);

        $this->assertSame('svc:unknown', $key->name);
    }

    public function testPathSectionPieceUsesRootWhenPathIsEmptyOrSlash(): void
    {
        $req1 = $this->makeRequest('example.com', '', []);
        $req2 = $this->makeRequest('example.com', '/', []);

        $piece = new PathSectionDimensionKeyPiece(2, 'path_section');

        $k1 = (new CircuitBreakerKeyFactory('svc', [new HostKeyPiece(), $piece]))->build($req1);
        $k2 = (new CircuitBreakerKeyFactory('svc', [new HostKeyPiece(), $piece]))->build($req2);

        $this->assertSame('root', $k1->dimensions['path_section']);
        $this->assertSame('root', $k2->dimensions['path_section']);
    }

    public function testValueDimensionKeyPieceContributesFixedDimensionAndSkipsEmptyValues(): void
    {
        $req = $this->makeRequest('example.com', '/', []);

        $factory = new CircuitBreakerKeyFactory('svc', [
            new HostKeyPiece(),
            new ValueDimensionKeyPiece('tenant', 't1'),
            new ValueDimensionKeyPiece('provider', 123),
            new ValueDimensionKeyPiece('empty_string', '   '),
            new ValueDimensionKeyPiece('null_value', null),
        ]);

        $key = $factory->build($req);

        $this->assertSame('svc:example.com', $key->name);
        $this->assertSame([
            'provider' => 123,
            'tenant' => 't1',
        ], $key->dimensions);
    }

    /**
     * @param array<string,string> $headers
     */
    private function makeRequest(string $host, string $path, array $headers): RequestInterface
    {
        $req = $this->createMock(RequestInterface::class);
        $uri = $this->createMock(UriInterface::class);

        $uri->method('getHost')->willReturn($host);
        $uri->method('getPath')->willReturn($path);
        $req->method('getUri')->willReturn($uri);

        $req->method('hasHeader')->willReturnCallback(function (string $name) use ($headers): bool {
            return array_key_exists($name, $headers);
        });
        $req->method('getHeaderLine')->willReturnCallback(function (string $name) use ($headers): string {
            return $headers[$name] ?? '';
        });

        return $req;
    }
}

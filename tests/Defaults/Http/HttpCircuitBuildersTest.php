<?php

declare(strict_types=1);

namespace tests\Defaults\Http;

use Gohany\Circuitbreaker\Defaults\Http\DefaultMultiHttpCircuitBuilder;
use Gohany\Circuitbreaker\Defaults\Http\PathSectionHttpCircuitBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class HttpCircuitBuildersTest extends TestCase
{
    public function testDefaultMultiBuildSecondaryKeyUsesTenantHeaderWhenPresent(): void
    {
        $req = $this->fakeRequest('api.example.test', '/v1/users/123', ['X-Tenant-Id' => 't1']);
        $b = new DefaultMultiHttpCircuitBuilder();

        $k = $b->buildSecondaryKey($req, 'cb');
        $this->assertNotNull($k);
        $this->assertSame('cb:t1', $k->name);
        $this->assertSame([], $k->dimensions);

        $req2 = $this->fakeRequest('api.example.test', '/v1/users/123', []);
        $this->assertNull($b->buildSecondaryKey($req2, 'cb'));
    }

    public function testPathSectionBuilderBuildsContextWithTrimmedTenantId(): void
    {
        $b = new PathSectionHttpCircuitBuilder(1, 'path_section', 'X-Tenant-Id');

        $req = $this->fakeRequest('api.example.test', '/v1/users/123', ['X-Tenant-Id' => '  t1  ']);
        $ctx = $b->buildContext($req);
        $this->assertSame('t1', $ctx->tenantId);

        $req2 = $this->fakeRequest('api.example.test', '/v1/users/123', ['X-Tenant-Id' => '   ']);
        $ctx2 = $b->buildContext($req2);
        $this->assertNull($ctx2->tenantId);
    }

    public function testPathSectionBuilderBuildsDeterministicKeyAndCollapsesIdsInFirstSectionOnly(): void
    {
        $b = new PathSectionHttpCircuitBuilder(1, 'path_section');

        $r1 = $this->fakeRequest('api.example.test', '/v1/users/123', []);
        $r2 = $this->fakeRequest('api.example.test', '/v1/users/456', []);

        $k1 = $b->buildKey($r1, 'cb');
        $k2 = $b->buildKey($r2, 'cb');

        // With sections=1, the first section is "v1" for both.
        $this->assertSame($k1->name, $k2->name);
        $this->assertSame($k1->dimensions, $k2->dimensions);
        $this->assertArrayHasKey('path_section', $k1->dimensions);
        $this->assertSame('v1', $k1->dimensions['path_section']);
    }

    /**
     * @param array<string,string> $headers
     */
    private function fakeRequest(string $host, string $path, array $headers): RequestInterface
    {
        $uri = new class($host, $path) implements UriInterface {
            private string $host;
            private string $path;
            public function __construct(string $host, string $path) { $this->host = $host; $this->path = $path; }
            public function getScheme(): string { return 'http'; }
            public function getAuthority(): string { return $this->host; }
            public function getUserInfo(): string { return ''; }
            public function getHost(): string { return $this->host; }
            public function getPort(): ?int { return null; }
            public function getPath(): string { return $this->path; }
            public function getQuery(): string { return ''; }
            public function getFragment(): string { return ''; }
            public function withScheme($scheme): UriInterface { throw new \BadMethodCallException(); }
            public function withUserInfo($user, $password = null): UriInterface { throw new \BadMethodCallException(); }
            public function withHost($host): UriInterface { throw new \BadMethodCallException(); }
            public function withPort($port): UriInterface { throw new \BadMethodCallException(); }
            public function withPath($path): UriInterface { throw new \BadMethodCallException(); }
            public function withQuery($query): UriInterface { throw new \BadMethodCallException(); }
            public function withFragment($fragment): UriInterface { throw new \BadMethodCallException(); }
            public function __toString(): string { return 'http://' . $this->host . $this->path; }
        };

        return new class($uri, $headers) implements RequestInterface {
            private UriInterface $uri;
            /** @var array<string,string> */
            private array $headers;
            public function __construct(UriInterface $uri, array $headers) { $this->uri = $uri; $this->headers = $headers; }

            public function getProtocolVersion(): string { return '1.1'; }
            public function withProtocolVersion($version): MessageInterface { throw new \BadMethodCallException(); }

            /** @return array<string,array<int,string>> */
            public function getHeaders(): array
            {
                $out = [];
                foreach ($this->headers as $k => $v) {
                    $out[$k] = [$v];
                }
                return $out;
            }

            public function hasHeader($name): bool { return array_key_exists((string) $name, $this->headers); }

            /** @return string[] */
            public function getHeader($name): array
            {
                return $this->hasHeader($name) ? [$this->headers[(string) $name]] : [];
            }

            public function getHeaderLine($name): string { return $this->headers[(string) $name] ?? ''; }
            public function withHeader($name, $value): MessageInterface { throw new \BadMethodCallException(); }
            public function withAddedHeader($name, $value): MessageInterface { throw new \BadMethodCallException(); }
            public function withoutHeader($name): MessageInterface { throw new \BadMethodCallException(); }
            public function getBody(): StreamInterface { throw new \BadMethodCallException(); }
            public function withBody(StreamInterface $body): MessageInterface { throw new \BadMethodCallException(); }

            public function getRequestTarget(): string { return ''; }
            public function withRequestTarget($requestTarget): RequestInterface { throw new \BadMethodCallException(); }
            public function getMethod(): string { return 'GET'; }
            public function withMethod($method): RequestInterface { throw new \BadMethodCallException(); }
            public function getUri(): UriInterface { return $this->uri; }
            public function withUri(UriInterface $uri, $preserveHost = false): RequestInterface { throw new \BadMethodCallException(); }
        };
    }
}

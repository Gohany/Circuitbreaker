<?php

declare(strict_types=1);

/**
 * Circuit breaker integration sanity-check script.
 *
 * Copy this file into your app, tweak wiring, and run it against your real Redis.
 *
 * Usage examples (from repo root):
 *
 *   php tools/circuit_sanity_check.php --mode=single
 *   php tools/circuit_sanity_check.php --mode=multi
 *
 * Configuration via environment:
 *   CB_REDIS_HOST=127.0.0.1
 *   CB_REDIS_PORT=6379
 *   CB_REDIS_DB=0
 *   CB_REDIS_PREFIX=cb_sanity
 *   CB_HUMAN_KEYS=1
 */

require __DIR__ . '/../vendor/autoload.php';

use Gohany\Circuitbreaker\Integration\Sanity\Output\AnsiSanityCheckOutput;
use Gohany\Circuitbreaker\Integration\Sanity\Output\OutputSideEffectDispatcher;
use Gohany\Circuitbreaker\Integration\Sanity\Output\PlainSanityCheckOutput;
use Gohany\Circuitbreaker\Integration\Sanity\Output\SanityCheckOutputInterface;
use Gohany\Circuitbreaker\Integration\Sanity\SanityCheckRunner;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\OutcomeClassifierInterface;
use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;
use Gohany\Circuitbreaker\Policy\Http\HttpCircuitPolicyConfig;
use Gohany\Circuitbreaker\Store\InMemoryCircuitHistoryStore;
use Gohany\Circuitbreaker\Store\InMemoryCircuitStateStore;
use Gohany\Circuitbreaker\Store\InMemoryProbeGate;
use Gohany\Circuitbreaker\Store\Redis\ExtRedisClient;
use Gohany\Circuitbreaker\Store\Redis\RedisCircuitHistoryStore;
use Gohany\Circuitbreaker\Store\Redis\RedisCircuitStateStore;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;
use Gohany\Circuitbreaker\Store\Redis\RedisProbeGate;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

// -------------------------
// CLI parsing
// -------------------------

$args = parseArgs($argv);
$mode = (string) ($args['mode'] ?? 'single');
if ($mode !== 'single' && $mode !== 'multi') {
    fwrite(STDERR, "Unknown --mode. Use --mode=single or --mode=multi\n");
    exit(2);
}

$storeArg = (string) ($args['store'] ?? (getenv('CB_STORE') ?: 'auto'));
if (!in_array($storeArg, ['auto', 'redis', 'memory'], true)) {
    fwrite(STDERR, "Unknown --store. Use --store=auto|redis|memory\n");
    exit(2);
}

$noColor = isset($args['no-color']) || isset($args['no_color']);

// -------------------------
// Storage wiring (Redis or in-memory)
// -------------------------

$prefix = getenv('CB_REDIS_PREFIX') ?: 'cb_sanity';
$humanKeys = (getenv('CB_HUMAN_KEYS') ?: '') !== '';

$storeLabel = 'memory';

if ($storeArg === 'redis' || ($storeArg === 'auto' && extension_loaded('redis'))) {
    if (!extension_loaded('redis')) {
        fwrite(STDERR, "The PHP ext-redis extension is not loaded. Falling back to in-memory store.\n");
    } else {
        $redisHost = getenv('CB_REDIS_HOST') ?: '127.0.0.1';
        $redisPort = (int) (getenv('CB_REDIS_PORT') ?: 6379);
        $redisDb = (int) (getenv('CB_REDIS_DB') ?: 0);

        try {
            $redis = new Redis();
            $ok = $redis->connect($redisHost, $redisPort, 1.0);
            if (!$ok) {
                throw new RuntimeException("connect() returned false");
            }

            if ($redisDb !== 0 && !$redis->select($redisDb)) {
                throw new RuntimeException("select({$redisDb}) returned false");
            }

            $client = new ExtRedisClient($redis);
            $keys = new RedisKeyBuilder($prefix, $humanKeys);
            $stateStore = new RedisCircuitStateStore($client, $keys);
            $historyStore = new RedisCircuitHistoryStore($client, $keys);
            $probeGate = new RedisProbeGate($client, $keys);
            $storeLabel = "redis {$redisHost}:{$redisPort} db={$redisDb} prefix={$prefix} humanKeys=" . ($humanKeys ? '1' : '0');
        } catch (Throwable $e) {
            if ($storeArg === 'redis') {
                fwrite(STDERR, "Failed to use Redis store (forced): {$e->getMessage()}\n");
                exit(4);
            }
            fwrite(STDERR, "Failed to use Redis store; falling back to in-memory store: {$e->getMessage()}\n");
        }
    }
}

if (!isset($stateStore, $historyStore, $probeGate)) {
    $stateStore = new InMemoryCircuitStateStore();
    $historyStore = new InMemoryCircuitHistoryStore();
    $probeGate = new InMemoryProbeGate();
    $storeLabel = 'memory (in-process)';
}

// -------------------------
// Output (colors + clearer steps)
// -------------------------

/**
 * Very small TTY check (best-effort). If we can't tell, default to "no color".
 *
 * phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
 */
$isTty = false;
if (!$noColor) {
    if (function_exists('stream_isatty')) {
        $isTty = @stream_isatty(STDOUT);
    } elseif (function_exists('posix_isatty')) {
        $isTty = @posix_isatty(STDOUT);
    }
}

/** @var SanityCheckOutputInterface $out */
$out = ($isTty && !$noColor)
    ? new AnsiSanityCheckOutput(STDOUT)
    : new PlainSanityCheckOutput(STDOUT);

// -------------------------
// Visibility: print side effects
// -------------------------

$sideEffects = new OutputSideEffectDispatcher($out);

// -------------------------
// Classifier used in both demos
// -------------------------

$classifier = new class implements OutcomeClassifierInterface {
    public function classify($result, $error, array $context = []): CircuitOutcome
    {
        if ($error instanceof \Throwable) {
            // Map a generic error to a standard failure signal.
            return new CircuitOutcome(false, ['timeout'], $error, $context, 0);
        }

        if ($result instanceof ResponseInterface) {
            $code = $result->getStatusCode();
            if ($code === 429) {
                return new CircuitOutcome(false, ['http_429'], null, $context, 0);
            }
            if ($code >= 500) {
                return new CircuitOutcome(false, ['http_5xx'], null, $context, 0);
            }
        }

        return new CircuitOutcome(true, [], null, $context, 0);
    }
};

// -------------------------
// Policy configuration: open quickly so you can see it happen
// -------------------------

$httpCfg = new HttpCircuitPolicyConfig();
$httpCfg->consecutiveFailuresToOpen = 2;
$httpCfg->openDurationMs = 4000;
$httpCfg->openMinDurationMs = 1000;
$httpCfg->halfOpenSuccessesToClose = 1;
$httpCfg->halfOpenFailuresToOpen = 1;

$httpPolicy = new DefaultHttpCircuitPolicy($httpCfg);

/**
 * @return array<string,string|true>
 */
function parseArgs(array $argv): array
{
    $out = [];
    foreach ($argv as $i => $a) {
        if ($i === 0) {
            continue;
        }
        if (strpos($a, '--') !== 0) {
            continue;
        }
        $a = substr($a, 2);
        $eq = strpos($a, '=');
        if ($eq === false) {
            $out[$a] = true;
            continue;
        }
        $k = substr($a, 0, $eq);
        $v = substr($a, $eq + 1);
        $out[$k] = $v;
    }
    return $out;
}

// -------------------------
// Minimal PSR-7 stubs (so this script has no extra deps)
// -------------------------

final class SimpleUri implements UriInterface
{
    private string $host;
    private string $path;

    public function __construct(string $host, string $path)
    {
        $this->host = $host;
        $this->path = $path;
    }

    public function getHost(): string { return $this->host; }
    public function getPath(): string { return $this->path; }

    public function __toString(): string { return 'https://' . $this->host . $this->path; }
    public function getScheme(): string { return 'https'; }
    public function getAuthority(): string { return $this->host; }
    public function getUserInfo(): string { return ''; }
    public function getPort(): ?int { return null; }
    public function getQuery(): string { return ''; }
    public function getFragment(): string { return ''; }
    public function withScheme($scheme): UriInterface { throw new BadMethodCallException('not implemented'); }
    public function withUserInfo($user, $password = null): UriInterface { throw new BadMethodCallException('not implemented'); }
    public function withHost($host): UriInterface { throw new BadMethodCallException('not implemented'); }
    public function withPort($port): UriInterface { throw new BadMethodCallException('not implemented'); }
    public function withPath($path): UriInterface { throw new BadMethodCallException('not implemented'); }
    public function withQuery($query): UriInterface { throw new BadMethodCallException('not implemented'); }
    public function withFragment($fragment): UriInterface { throw new BadMethodCallException('not implemented'); }
}

final class SimpleRequest implements RequestInterface
{
    /** @var array<string,string> */
    private array $headers;
    private UriInterface $uri;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(string $url, array $headers = [])
    {
        $parts = parse_url($url);
        $host = isset($parts['host']) ? (string) $parts['host'] : 'unknown';
        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        $this->uri = new SimpleUri($host, $path);
        $this->headers = $headers;
    }

    public function getUri(): UriInterface { return $this->uri; }
    public function hasHeader($name): bool { return array_key_exists((string) $name, $this->headers); }
    public function getHeaderLine($name): string { return (string) ($this->headers[(string) $name] ?? ''); }
    public function getHeader($name): array
    {
        $v = $this->getHeaderLine($name);
        return $v === '' ? [] : [$v];
    }

    public function withHeader($name, $value): RequestInterface { throw new BadMethodCallException('not implemented'); }
    public function withAddedHeader($name, $value): RequestInterface { throw new BadMethodCallException('not implemented'); }
    public function withoutHeader($name): RequestInterface { throw new BadMethodCallException('not implemented'); }
    public function getHeaders(): array { return $this->headers; }

    public function getRequestTarget(): string { return $this->uri->getPath(); }
    public function withRequestTarget($requestTarget): RequestInterface { throw new BadMethodCallException('not implemented'); }
    public function getMethod(): string { return 'GET'; }
    public function withMethod($method): RequestInterface { throw new BadMethodCallException('not implemented'); }
    public function withUri(UriInterface $uri, $preserveHost = false): RequestInterface { throw new BadMethodCallException('not implemented'); }

    public function getProtocolVersion(): string { return '1.1'; }
    public function withProtocolVersion($version): RequestInterface { throw new BadMethodCallException('not implemented'); }

    public function getBody(): StreamInterface { throw new BadMethodCallException('not implemented'); }
    public function withBody(StreamInterface $body): RequestInterface { throw new BadMethodCallException('not implemented'); }
}

final class SimpleResponse implements ResponseInterface
{
    private int $status;
    /** @var array<string,string> */
    private array $headers;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(int $status, array $headers)
    {
        $this->status = $status;
        $this->headers = $headers;
    }

    public function getStatusCode(): int { return $this->status; }
    public function hasHeader($name): bool { return array_key_exists((string) $name, $this->headers); }
    public function getHeaderLine($name): string { return (string) ($this->headers[(string) $name] ?? ''); }
    public function getHeader($name): array
    {
        $v = $this->getHeaderLine($name);
        return $v === '' ? [] : [$v];
    }

    public function getHeaders(): array { return $this->headers; }
    public function withHeader($name, $value): ResponseInterface { throw new BadMethodCallException('not implemented'); }
    public function withAddedHeader($name, $value): ResponseInterface { throw new BadMethodCallException('not implemented'); }
    public function withoutHeader($name): ResponseInterface { throw new BadMethodCallException('not implemented'); }

    public function getReasonPhrase(): string { return ''; }
    public function withStatus($code, $reasonPhrase = ''): ResponseInterface { throw new BadMethodCallException('not implemented'); }

    public function getProtocolVersion(): string { return '1.1'; }
    public function withProtocolVersion($version): ResponseInterface { throw new BadMethodCallException('not implemented'); }

    public function getBody(): StreamInterface { throw new BadMethodCallException('not implemented'); }
    public function withBody(StreamInterface $body): ResponseInterface { throw new BadMethodCallException('not implemented'); }
}

// -------------------------
// Run the selected scenario (after stubs are defined)
// -------------------------

$out->heading('Circuit breaker integration sanity check');
$out->info('mode=' . $mode . ' store=' . $storeLabel . ' color=' . (($isTty && !$noColor) ? 'ansi' : 'plain'));

$runner = new SanityCheckRunner();

if ($mode === 'single') {
    $result = $runner->runSingle($stateStore, $historyStore, $probeGate, $httpPolicy, $classifier, $sideEffects, $out);
} else {
    $innerHttp = new class implements ClientInterface {
        private int $call = 0;

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->call++;

            if ($this->call === 1) {
                return new SimpleResponse(500, []);
            }

            if ($this->call === 2) {
                return new SimpleResponse(200, ['X-Fraud-Suspected' => '1']);
            }

            return new SimpleResponse(200, []);
        }
    };

    $req = new SimpleRequest('https://api.example.test/v1/charges', [
        'X-Tenant-Id' => 't_sanity',
    ]);

    $result = $runner->runMulti($stateStore, $historyStore, $probeGate, $httpPolicy, $classifier, $sideEffects, $out, $req, $innerHttp);
}

if (!$result->isOk()) {
    foreach ($result->failures as $f) {
        $out->fail($f);
    }
    exit(1);
}

exit(0);

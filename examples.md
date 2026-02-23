# Examples

Concrete usage patterns for `gohany/circuitbreaker`.

## Table of contents

- [Core: protect any operation](#core-protect-any-operation)
- [HTTP: single-circuit PSR-18 decorator](#http-single-circuit-psr-18-decorator)
  - [Custom key/context building](#custom-keycontext-building)
  - [Built-in: path section scoping](#built-in-path-section-scoping)
- [HTTP key composition: `CircuitBreakerKeyFactory` + pieces](#http-key-composition-circuitbreakerkeyfactory--pieces)
- [HTTP: multiple circuits per request](#http-multiple-circuits-per-request)
- [Pattern: dual-key reliability + tenant fraud lockout](#pattern-dual-key-reliability--tenant-fraud-lockout)
- [Explicit request-level configuration](#explicit-request-level-configuration)
- [Retries](#retries)
  - [Using `SaneRetryPolicies`](#using-saneretrypolicies)
  - [Custom policy with retry spec](#custom-policy-with-retry-spec)
- [Bulkheads](#bulkheads)
  - [Local semaphore bulkhead](#local-semaphore-bulkhead)
  - [Redis pool bulkhead (global max concurrency)](#redis-pool-bulkhead-global-max-concurrency)
  - [Redis fair-queue bulkhead (lanes + fairness)](#redis-fair-queue-bulkhead-lanes--fairness)
- [Resilience pipeline (middleware composition)](#resilience-pipeline-middleware-composition)
- [Operational overrides (Redis)](#operational-overrides-redis)
- [Observability: `EmitterInterface` and PSR-3 logging](#observability-emitterinterface-and-psr-3-logging)
- [Storage backends](#storage-backends)
  - [Redis wiring example](#redis-wiring-example)
  - [PDO (SQL) storage example](#pdo-sql-storage-example)
  - [APCu (shared memory) storage example](#apcu-shared-memory-storage-example)
- [Integration sanity check script](#integration-sanity-check-script)
  - [Fair-queue sanity scripts](#fair-queue-sanity-scripts)

---

## Core: protect any operation

You can protect any operation by wrapping it in `CircuitBreaker::execute(...)`.

```php
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Exception\CircuitDeniedException;

/** @var CircuitBreaker $breaker */

try {
    $result = $breaker->execute(
        new CircuitKey('database:write:users'),
        new CircuitContext('tenant-123'),
        function () use ($db, $userData) {
            return $db->insert('users', $userData);
        }
    );
} catch (CircuitDeniedException $e) {
    // Handle blocked action
}
```

---

## HTTP: single-circuit PSR-18 decorator

Use `CircuitBreakingPsr18Client` to add circuit breaking to any PSR-18 client.

```php
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakingPsr18Client;
use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;

/** @var Psr\Http\Client\ClientInterface $psr18Client */
/** @var Gohany\Circuitbreaker\Store\CircuitStateStoreInterface $stateStore */
/** @var Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface $historyStore */

$policy = new DefaultHttpCircuitPolicy();
$breaker = new CircuitBreaker(
    $stateStore,
    $historyStore,
    $policy,
    new MyOutcomeClassifier() // implement OutcomeClassifierInterface for your stack
);

$client = new CircuitBreakingPsr18Client($psr18Client, $breaker, 'my-service');
$response = $client->sendRequest($request);
```

### Custom key/context building

Pass a custom `HttpCircuitBuilderInterface` to change how keys/contexts are built.

```php
use Gohany\Circuitbreaker\Defaults\Http\DefaultHttpCircuitBuilder;
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakingPsr18Client;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Psr\Http\Message\RequestInterface;

final class MyCustomBuilder extends DefaultHttpCircuitBuilder
{
    public function buildKey(RequestInterface $request, string $prefix): CircuitKey
    {
        // Example: include full path (high-cardinality; use carefully)
        return new CircuitKey($prefix . ':' . $request->getUri()->getHost() . ':' . $request->getUri()->getPath());
    }
}

$client = new CircuitBreakingPsr18Client($psr18Client, $breaker, 'my-service', new MyCustomBuilder());
```

### Built-in: path section scoping

`PathSectionHttpCircuitBuilder` scopes a host into multiple circuits by the first N path segments.

```php
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakingPsr18Client;
use Gohany\Circuitbreaker\Defaults\Http\PathSectionHttpCircuitBuilder;

// Example: /v1/charges/123 -> dimensions[path_section] = "v1/charges"
$client = new CircuitBreakingPsr18Client(
    $psr18Client,
    $breaker,
    'my-service',
    new PathSectionHttpCircuitBuilder(2)
);
```

---

## HTTP key composition: `CircuitBreakerKeyFactory` + pieces

If you want deterministic, order-independent key construction, build keys from key pieces.

```php
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerKeyFactory;
use Gohany\Circuitbreaker\Defaults\Http\Pieces\HeaderDimensionKeyPiece;
use Gohany\Circuitbreaker\Defaults\Http\Pieces\HostKeyPiece;
use Gohany\Circuitbreaker\Defaults\Http\Pieces\PathSectionDimensionKeyPiece;
use Gohany\Circuitbreaker\Defaults\Http\Pieces\ValueDimensionKeyPiece;

$factory = new CircuitBreakerKeyFactory('my-service', [
    // Order does not matter; the factory sorts pieces by `id()`.
    new PathSectionDimensionKeyPiece(2, 'path_section'),
    new HostKeyPiece(),
    new HeaderDimensionKeyPiece('http.tenant', 'X-Tenant-Id', 'tenant'),
    new HeaderDimensionKeyPiece('http.provider', 'X-Provider-Id', 'provider'),
    // Add constant, app-provided dimensions too (not derived from the request).
    new ValueDimensionKeyPiece('env', 'prod'),
]);

$key = $factory->build($request);
```

---

## HTTP: multiple circuits per request

If you want a single PSR-18 client to coordinate an **ordered list of circuits** per request (for example, provider/host reliability + tenant fraud lockout), use `MultiCircuitBreakingPsr18Client`.

Semantics:
- Pre-checks each circuit via `decide(...)` (in order)
- Sends the request through the inner PSR-18 client (no circuit wraps the call)
- Classifies + `recordOutcome(...)` for each circuit (in order)

```php
use Gohany\Circuitbreaker\Defaults\Http\DefaultMultiHttpCircuitsBuilder;
use Gohany\Circuitbreaker\Defaults\Http\HttpCircuitDefinition;
use Gohany\Circuitbreaker\Defaults\Http\MultiCircuitBreakingPsr18Client;

$client = new MultiCircuitBreakingPsr18Client(
    $psr18Client,
    [
        // 1) Host/provider reliability circuit
        new HttpCircuitDefinition($reliabilityBreaker, $reliabilityOutcomeClassifier, 'payments_http', false),
        // 2) Tenant fraud circuit (disabled when `X-Tenant-Id` is missing)
        new HttpCircuitDefinition($fraudBreaker, $fraudOutcomeClassifier, 'payments_fraud', true),
    ],
    new DefaultMultiHttpCircuitsBuilder()
);

$response = $client->sendRequest($request);
```

---

## Pattern: dual-key reliability + tenant fraud lockout

Sometimes you want **network reliability** to be tracked per provider/host, but **fraud lockout** to be tracked per tenant.
That means you intentionally use **two different `CircuitKey`s**.

- Reliability key: `payments_http:{provider}`
- Fraud key: `payments_fraud:{tenant}`

You can update the tenant-scoped fraud circuit without running a second dummy `execute()` by calling `CircuitBreakerInterface::recordOutcome(...)`.

```php
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\Fraud\FraudLockoutConfig;
use Gohany\Circuitbreaker\Policy\Fraud\FraudLockoutPolicyDecorator;
use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;

// Reliability breaker (provider/host-scoped)
$reliabilityBreaker = new CircuitBreaker(
    $stateStore,
    $historyStore,
    new DefaultHttpCircuitPolicy(),
    $classifier,
    [],
    $sideEffects,
    $clock,
    $probeGate
);

// Fraud breaker (tenant-scoped)
$fraudBreaker = new CircuitBreaker(
    $stateStore,
    $historyStore,
    new FraudLockoutPolicyDecorator(
        new DefaultHttpCircuitPolicy(),
        new FraudLockoutConfig([
            'lockoutMs' => 15 * 60 * 1000,
            'fraudSignals' => ['fraud_suspected'],
        ])
    ),
    $classifier,
    [],
    $sideEffects,
    $clock,
    $probeGate
);

$ctx = new CircuitContext($tenantId);

// 1) Optional: pre-check tenant fraud lockout
$fraudBreaker->decide(new CircuitKey('payments_fraud', ['tenant' => $tenantId]), $ctx);

// 2) Run the network call under provider reliability circuit
$response = $reliabilityBreaker->execute(
    new CircuitKey('payments_http', ['provider' => $provider]),
    $ctx,
    fn () => $psr18Client->sendRequest($request)
);

// 3) If you detect fraud, record it against the tenant circuit
if ($response->hasHeader('X-Fraud-Suspected')) {
    $fraudBreaker->recordOutcome(
        new CircuitKey('payments_fraud', ['tenant' => $tenantId]),
        $ctx,
        new CircuitOutcome(true, ['fraud_suspected'], null, [], 0)
    );
}
```

---

## Explicit request-level configuration

For single-circuit HTTP usage, you can implement `CircuitBreakerRequestInterface` on your PSR-7 request objects to explicitly define the circuit key/context.

```php
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerRequestInterface;

final class MyCustomRequest implements CircuitBreakerRequestInterface
{
    // ... implement PSR-7 methods ...

    public function getCircuitKey(): ?CircuitKey
    {
        return new CircuitKey('high-priority:manual-key');
    }

    public function getCircuitContext(): ?CircuitContext
    {
        return new CircuitContext('tenant-override');
    }
}
```

---

## Retries

### Using `SaneRetryPolicies`

```php
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Defaults\Rtry\SaneRetryPolicies;
use Gohany\Circuitbreaker\Integration\Rtry\RtryRetryExecutor;

$retryExecutor = new RtryRetryExecutor($classifier);

$breaker = new CircuitBreaker(
    $stateStore,
    $historyStore,
    $policy,
    $classifier,
    [],
    new \Gohany\Circuitbreaker\SideEffect\NullSideEffectDispatcher(),
    new \Gohany\Circuitbreaker\Util\NativeClock(),
    new \Gohany\Circuitbreaker\Store\InMemoryProbeGate(),
    $retryExecutor,
    SaneRetryPolicies::defaultHttp()
);
```

### Custom policy with retry spec

```php
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Defaults\Rtry\SaneRetryPolicies;
use Gohany\Circuitbreaker\Integration\Rtry\RetrySpec;
use Gohany\Circuitbreaker\Policy\Http\AbstractHttpCircuitPolicy;

final class MyServicePolicy extends AbstractHttpCircuitPolicy
{
    public function getRetrySpec(CircuitKey $key, CircuitContext $context): ?RetrySpec
    {
        return new RetrySpec(SaneRetryPolicies::defaultHttp());
    }
}
```

---

## Storage backends

### Redis wiring example

```php
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakingPsr18Client;
use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;
use Gohany\Circuitbreaker\Store\Redis\RedisCircuitHistoryStore;
use Gohany\Circuitbreaker\Store\Redis\RedisCircuitStateStore;
use Gohany\Circuitbreaker\Store\Redis\ExtRedisClient as StoreExtRedisClient;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;
use Gohany\Circuitbreaker\Store\Redis\RedisProbeGate;

$redis = new \Redis();
$redis->connect('127.0.0.1');

$storeRedis = new StoreExtRedisClient($redis);

$kb = new RedisKeyBuilder('cb', true);

$breaker = new CircuitBreaker(
    new RedisCircuitStateStore($storeRedis, $kb),
    new RedisCircuitHistoryStore($storeRedis, $kb),
    new DefaultHttpCircuitPolicy(),
    new MyOutcomeClassifier(),
    [],
    new MySideEffectDispatcher(),
    new NativeClock(),
    new RedisProbeGate($storeRedis, $kb)
);

$httpClient = new CircuitBreakingPsr18Client($innerClient, $breaker);
```

### PDO (SQL) storage example

```php
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Store\Pdo\PdoCircuitHistoryStore;
use Gohany\Circuitbreaker\Store\Pdo\PdoCircuitStateStore;
use Gohany\Circuitbreaker\Store\Pdo\PdoProbeGate;

/** @var PDO $pdo */

$breaker = new CircuitBreaker(
    new PdoCircuitStateStore($pdo),
    new PdoCircuitHistoryStore($pdo),
    $policy,
    $classifier,
    [],
    new NullSideEffectDispatcher(),
    new NativeClock(),
    new PdoProbeGate($pdo)
);
```

#### Required SQL schema (PDO)

The complete schema is in `src/Store/Pdo/schema.sql`.

```sql
CREATE TABLE IF NOT EXISTS circuit_states (
    circuit_key VARCHAR(255) PRIMARY KEY,
    mode VARCHAR(32) NOT NULL,
    open_until_ms BIGINT,
    half_open_in_flight INT NOT NULL DEFAULT 0,
    meta_json TEXT,
    version INT NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS circuit_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT, -- Or SERIAL/BIGSERIAL
    circuit_key VARCHAR(255) NOT NULL,
    ts_ms BIGINT NOT NULL,
    success INT NOT NULL,
    signals_json TEXT,
    duration_ms INT NOT NULL DEFAULT 0,
    attributes_json TEXT
);
CREATE INDEX IF NOT EXISTS idx_circuit_history_key ON circuit_history(circuit_key, ts_ms);

CREATE TABLE IF NOT EXISTS circuit_probe_gates (
    circuit_key VARCHAR(255) PRIMARY KEY,
    in_flight INT NOT NULL
);
```

### APCu (shared memory) storage example

```php
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Store\Apcu\ApcuCircuitHistoryStore;
use Gohany\Circuitbreaker\Store\Apcu\ApcuCircuitStateStore;
use Gohany\Circuitbreaker\Store\Apcu\ApcuProbeGate;

$breaker = new CircuitBreaker(
    new ApcuCircuitStateStore(),
    new ApcuCircuitHistoryStore(),
    $policy,
    $classifier,
    [],
    new NullSideEffectDispatcher(),
    new NativeClock(),
    new ApcuProbeGate()
);
```

---

## Bulkheads

Bulkheads are concurrency limits.

They answer a different question than circuit breakers:

- circuit breaker: "should we try?"
- bulkhead: "how many may try concurrently?"

### Local semaphore bulkhead

Use `SemaphoreBulkhead` when you want a per-process limit.

```php
use Gohany\Circuitbreaker\Bulkhead\SemaphoreBulkhead;

$bulkhead = new SemaphoreBulkhead(10);

$permit = $bulkhead->acquire('default');
try {
    // do work
} finally {
    $permit->release();
}
```

### Redis pool bulkhead (global max concurrency)

Use `RedisPoolBulkhead` when you want a shared max concurrency across many nodes.

```php
use Gohany\Circuitbreaker\Bulkhead\RedisPoolBulkhead;
use Gohany\Circuitbreaker\Util\ExtRedisClient;

$redis = new \Redis();
$redis->connect('127.0.0.1');

$client = new ExtRedisClient($redis);
$bulkhead = new RedisPoolBulkhead($client, 'db-main', 50, 'cb');

$permit = $bulkhead->acquire('default');
try {
    // do work
} finally {
    $permit->release();
}
```

### Redis fair-queue bulkhead (lanes + fairness)

`RedisFairQueueBulkhead` is a distributed wait-queue bulkhead that supports lanes.

It is useful when you want to protect a shared dependency with both:

- a global cap
- lane caps (fixed / percent / weighted)
- a queue that avoids head-of-line blocking

```php
use Gohany\Circuitbreaker\Bulkhead\LanePolicy;
use Gohany\Circuitbreaker\Bulkhead\PoolPolicy;
use Gohany\Circuitbreaker\Bulkhead\RedisFairQueueBulkhead;
use Gohany\Circuitbreaker\Util\ExtRedisClient;

$redis = new \Redis();
$redis->connect('127.0.0.1');

$client = new ExtRedisClient($redis);

$policy = new PoolPolicy(
    'db-main',
    20,
    PoolPolicy::MODE_WEIGHTED,
    0.60,
    [
        'auth.login' => LanePolicy::weight('auth.login', 1),
        'payments.charge' => LanePolicy::weight('payments.charge', 6),
    ]
);

$bulkhead = new RedisFairQueueBulkhead($client, $policy, 'cb', null, [
    'scan_limit' => 64,
    'pump_per_call' => 3,
    'grant_ttl_ms' => 250,
    'poll_interval_ms' => 10,
]);

$permit = $bulkhead->acquire('payments.charge', 0.25);
try {
    // do work
} finally {
    $permit->release();
}
```

#### Global budget by number (absolute lane caps)

If you want a hard “numbers-based” budget per lane (e.g. payments gets 8 concurrent slots, login gets 2),
use `PoolPolicy::MODE_FIXED` with `LanePolicy::fixed()`:

```php
use Gohany\Circuitbreaker\Bulkhead\LanePolicy;
use Gohany\Circuitbreaker\Bulkhead\PoolPolicy;
use Gohany\Circuitbreaker\Bulkhead\RedisFairQueueBulkhead;
use Gohany\Circuitbreaker\Util\ExtRedisClient;

$redis = new \Redis();
$redis->connect('127.0.0.1');

$client = new ExtRedisClient($redis);

$policy = new PoolPolicy(
    'db-main',
    10, // global max concurrency across all lanes
    PoolPolicy::MODE_FIXED,
    0.60, // soft borrowing threshold (see README for details)
    [
        // Absolute per-lane caps
        'auth.login' => LanePolicy::fixed('auth.login', 2),
        'payments.charge' => LanePolicy::fixed('payments.charge', 8),
    ]
);

$bulkhead = new RedisFairQueueBulkhead($client, $policy, 'cb');

$permit = $bulkhead->acquire('payments.charge', 0.25);
try {
    // do work
} finally {
    $permit->release();
}
```

#### Global budget by percent (lane caps derived from `globalMax`)

If you want to allocate a *fraction* of the global pool to each lane (e.g. login gets 10%, payments gets 90%),
use `PoolPolicy::MODE_PERCENT` with `LanePolicy::percent()`:

```php
use Gohany\Circuitbreaker\Bulkhead\LanePolicy;
use Gohany\Circuitbreaker\Bulkhead\PoolPolicy;
use Gohany\Circuitbreaker\Bulkhead\RedisFairQueueBulkhead;
use Gohany\Circuitbreaker\Util\ExtRedisClient;

$redis = new \Redis();
$redis->connect('127.0.0.1');

$client = new ExtRedisClient($redis);

$policy = new PoolPolicy(
    'db-main',
    50, // global max concurrency across all lanes
    PoolPolicy::MODE_PERCENT,
    0.60,
    [
        // Percent-of-global lane budgets
        'auth.login' => LanePolicy::percent('auth.login', 0.10),
        'payments.charge' => LanePolicy::percent('payments.charge', 0.90),
    ]
);

$bulkhead = new RedisFairQueueBulkhead($client, $policy, 'cb');

$permit = $bulkhead->acquire('auth.login', 0.25);
try {
    // do work
} finally {
    $permit->release();
}
```

Note: in percent mode, `RedisFairQueueBulkhead` clamps each computed lane cap to at least 1
(`max(1, floor(globalMax * pct))`). This prevents “0-cap lanes” when `globalMax` is small.

---

## Resilience pipeline (middleware composition)

When you want one entry point for resilience, use `ResiliencePipeline`.

```php
use Gohany\Circuitbreaker\Resilience\Context;
use Gohany\Circuitbreaker\Resilience\ResiliencePipeline;
use Gohany\Circuitbreaker\Resilience\CircuitBreakerMiddleware;
use Gohany\Circuitbreaker\Resilience\BulkheadMiddleware;
use Gohany\Circuitbreaker\Resilience\MapLaneRouter;
use Gohany\Circuitbreaker\Resilience\RtryRetryMiddleware;

// $breaker = ... (Core\CircuitBreaker)
// $bulkhead = ... (BulkheadInterface)
// $retry = ... (Integration\Rtry\RetryExecutorInterface)

$laneRouter = new MapLaneRouter('route',
    [
        // Exact route name mapping (framework-specific)
        'auth_login' => 'auth.login',
        'payments_charge' => 'payments.charge',
    ],
    [
        // Prefix mapping (optional)
        'payments_' => 'payments.charge',
    ],
    [
        // Regex mapping (optional)
        '/^payments\./' => 'payments.charge',
    ],
    'auth.login'
);

$pipeline = new ResiliencePipeline([
    new BulkheadMiddleware($bulkhead, null, $laneRouter),
    new CircuitBreakerMiddleware($breaker),
    new RtryRetryMiddleware('rtry:attempts=3;delay=50ms;on=default'),
]);

$ctx = new Context('payments.charge', 'db-main');
$ctx->set('route', 'payments_charge');

$result = $pipeline->execute($ctx, function () {
    // risky operation
    return 'ok';
});
```

---

## Operational overrides (Redis)

Overrides are for incident response: force allow/deny/open temporarily.

```php
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Override\Redis\RedisOverrideDecider;
use Gohany\Circuitbreaker\Override\Redis\RedisOverrideStore;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;
use Gohany\Circuitbreaker\Store\Redis\ExtRedisClient as StoreExtRedisClient;

$redis = new \Redis();
$redis->connect('127.0.0.1');

$storeRedis = new StoreExtRedisClient($redis);

$kb = new RedisKeyBuilder('cb', true);
$store = new RedisOverrideStore($storeRedis, $kb);
$decider = new RedisOverrideDecider($store, new \Gohany\Circuitbreaker\Util\NativeClock());

$key = new CircuitKey('payments_http', ['provider' => 'acme']);

// Example: force deny for 5 minutes
$store->set($key, [
    'force_allow' => '',
    'force_deny' => '1',
    'forced_mode' => 'open',
    'forced_until_ms' => (string) ((int) (microtime(true) * 1000) + 5 * 60 * 1000),
    'reason' => 'incident',
    'meta_json' => '{"ticket":"INC-123"}',
], 5 * 60);

$overrideDecision = $decider->decide($key, new CircuitContext(null, [], []));
```

---

## Observability: `EmitterInterface` and PSR-3 logging

Some modules emit structured events via `EmitterInterface`.

```php
use Gohany\Circuitbreaker\Observability\EmitterInterface;

final class StdoutEmitter implements EmitterInterface
{
    public function emit(string $name, array $fields = []): void
    {
        fwrite(STDOUT, json_encode(['event' => $name, 'fields' => $fields]) . "\n");
    }
}
```

The core circuit breaker also accepts a PSR-3 logger. See `tests/LoggingTest.php` for a minimal pattern.

## Integration sanity check script

If you want to sanity-check a *full integration* (real stores, real keys, side effects visible), run the copyable script:

- `tools/circuit_sanity_check.php`

It drives the breaker into `OPEN`/`HALF_OPEN`/`CLOSED`, and prints side effects to stdout so you can see transitions.

The script also supports basic colored output (green = pass, red = fail) when stdout is a TTY. To force plain output, pass:

```bash
php tools/circuit_sanity_check.php --mode=single --no-color
```

### Fair-queue sanity scripts

If you are using `Bulkhead\RedisFairQueueBulkhead` and you want a quick end-to-end verification against a real Redis:

```bash
# Basic fair-queue sanity run (prints OK/FAIL)
./bin/cb-sanity-fair-queue.sh

# Extended run (spawns the worker fixture and validates output)
./bin/cb-sanity-fair-queue-extended.sh
```

Both scripts use `GOHANY_CB_TEST_REDIS_DSN` / `GOHANY_CB_TEST_REDIS_PREFIX` (with reasonable defaults).

### Choose a store

By default (`--store=auto`) the script uses:

- Redis if `ext-redis` is available *and* Redis is reachable
- otherwise it falls back to an **in-process memory store**

You can force a mode:

```bash
# Force in-process stores (no Redis required)
php tools/circuit_sanity_check.php --mode=single --store=memory

# Force Redis (fails if ext/Redis not available)
php tools/circuit_sanity_check.php --mode=single --store=redis
```

### Configure (Redis only)

Set these env vars (defaults shown):

```bash
CB_REDIS_HOST=127.0.0.1
CB_REDIS_PORT=6379
CB_REDIS_DB=0
CB_REDIS_PREFIX=cb_sanity

# Optional: use human-readable keys instead of hashed dimension ids
CB_HUMAN_KEYS=1

# Optional: same as passing --store=...
CB_STORE=auto
```

### Run: single circuit demo

```bash
php tools/circuit_sanity_check.php --mode=single
```

What you should see:

- Two simulated failures
- Circuit opens and denies
- After a short sleep, a probe succeeds and the circuit closes
- `SIDE_EFFECT ...` JSON lines for open/close transitions

### Run: multi-circuit demo

```bash
php tools/circuit_sanity_check.php --mode=multi
```

What you should see:

- 1st request returns a simulated `500` (reliability circuit moves toward `OPEN`)
- 2nd request returns `200` with an injected fraud header (tenant fraud circuit opens)
- 3rd request is denied by the fraud circuit (post-record re-check)

### Notes

- The script uses a short open duration so you can see state changes quickly.
- For Redis runs, to reset state between runs, change `CB_REDIS_PREFIX` (recommended) or clear keys for that prefix in Redis.
- For memory runs, state exists only within the current PHP process.
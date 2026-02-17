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
- [Storage backends](#storage-backends)
  - [Redis wiring example](#redis-wiring-example)
  - [PDO (SQL) storage example](#pdo-sql-storage-example)
  - [APCu (shared memory) storage example](#apcu-shared-memory-storage-example)

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
use Gohany\Circuitbreaker\Store\Redis\RedisHistoryStore;
use Gohany\Circuitbreaker\Store\Redis\RedisProbeGate;
use Gohany\Circuitbreaker\Store\Redis\RedisStateStore;

$redis = new \Redis();
$redis->connect('127.0.0.1');

$breaker = new CircuitBreaker(
    new RedisStateStore($redis),
    new RedisHistoryStore($redis),
    new DefaultHttpCircuitPolicy(),
    new MyOutcomeClassifier(),
    [],
    new MySideEffectDispatcher(),
    new NativeClock(),
    new RedisProbeGate($redis)
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
    outcome VARCHAR(64) NOT NULL,
    recorded_at_ms BIGINT NOT NULL,
    details_json TEXT
);
CREATE INDEX IF NOT EXISTS idx_circuit_history_key ON circuit_history(circuit_key, recorded_at_ms);

CREATE TABLE IF NOT EXISTS circuit_probe_gates (
    circuit_key VARCHAR(255) PRIMARY KEY,
    expires_at_ms BIGINT NOT NULL
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

# Examples

This file provides concrete examples of how to use the `gohany/circuitbreaker` library with common patterns and the included defaults.

## Basic Generic Usage

You can protect any operation by wrapping it in the `execute` method.

```php
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Core\CircuitContext;

/** @var CircuitBreaker $breaker */

use Gohany\Circuitbreaker\Exception\CircuitDeniedException;

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

## PSR-18 HTTP Client Decorator

The library includes a `CircuitBreakingPsr18Client` to easily add circuit breaking to any PSR-18 compliant HTTP client.

```php
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakingPsr18Client;
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;

/** @var Psr\Http\Client\ClientInterface $psr18Client */
/** @var Gohany\Circuitbreaker\Store\CircuitStateStoreInterface $stateStore */
/** @var Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface $historyStore */

$policy = new DefaultHttpCircuitPolicy();
$breaker = new CircuitBreaker(
    $stateStore,
    $historyStore,
    $policy,
    new \Gohany\Circuitbreaker\Policy\Http\DefaultHttpOutcomeClassifier(), // You should implement this or use a default
    [],
    new \Gohany\Circuitbreaker\SideEffect\NullSideEffectDispatcher(),
    new \Gohany\Circuitbreaker\Util\NativeClock(),
    new \Gohany\Circuitbreaker\Store\InMemoryProbeGate()
);

$client = new CircuitBreakingPsr18Client($psr18Client, $breaker, 'my-service');

// All requests now go through the circuit breaker
// The default builder picks up 'X-Tenant-Id' header as tenantId.
$response = $client->sendRequest($request);

// --- OR with custom key/context building ---

use Gohany\Circuitbreaker\Defaults\Http\DefaultHttpCircuitBuilder;
use Psr\Http\Message\RequestInterface;
use Gohany\Circuitbreaker\Core\CircuitKey;

class MyCustomBuilder extends DefaultHttpCircuitBuilder
{
    public function buildKey(RequestInterface $request, string $prefix): CircuitKey
    {
        // Example: use path in the key for more granularity
        return new CircuitKey($prefix . ':' . $request->getUri()->getHost() . ':' . $request->getUri()->getPath());
    }
}

$client = new CircuitBreakingPsr18Client(
    $psr18Client, 
    $breaker, 
    'my-service', 
    new MyCustomBuilder()
);
```

## Explicit Request-level Configuration

You can implement `CircuitBreakerRequestInterface` on your PSR-7 request objects (or use a decorator) to explicitly define the circuit key and context for a specific request.

```php
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakerRequestInterface;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Psr\Http\Message\RequestInterface;

class MyCustomRequest implements CircuitBreakerRequestInterface
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

// When passed to CircuitBreakingPsr18Client, these values take precedence over the builder.
$response = $client->sendRequest(new MyCustomRequest());
```

## Using Sane Retry Policies

The `SaneRetryPolicies` class provides pre-configured `rtry` policies for common scenarios.

```php
use Gohany\Circuitbreaker\Defaults\Rtry\SaneRetryPolicies;
use Gohany\Circuitbreaker\Integration\Rtry\RtryRetryExecutor;
use Gohany\Circuitbreaker\Core\CircuitBreaker;

$retryExecutor = new RtryRetryExecutor($classifier);

// For idempotent GET requests
$idempotentPolicy = SaneRetryPolicies::defaultHttp();

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
    $idempotentPolicy
);

// For non-idempotent POST requests
$conservativePolicy = SaneRetryPolicies::conservativeWrite();

$breakerWithConservativeRetry = new CircuitBreaker(
    $stateStore,
    $historyStore,
    $policy,
    $classifier,
    [],
    new \Gohany\Circuitbreaker\SideEffect\NullSideEffectDispatcher(),
    new \Gohany\Circuitbreaker\Util\NativeClock(),
    new \Gohany\Circuitbreaker\Store\InMemoryProbeGate(),
    $retryExecutor,
    $conservativePolicy
);
```

## Custom Policy with Retry Spec

You can bake retry policies directly into your circuit policy by implementing `RetrySpecProviderInterface`.

```php
use Gohany\Circuitbreaker\Policy\Http\AbstractHttpCircuitPolicy;
use Gohany\Circuitbreaker\Integration\Rtry\RetrySpec;
use Gohany\Circuitbreaker\Defaults\Rtry\SaneRetryPolicies;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Core\CircuitContext;

class MyServicePolicy extends AbstractHttpCircuitPolicy
{
    public function getRetrySpec(CircuitKey $key, CircuitContext $context): ?RetrySpec
    {
        // Use the default sane policy
        return new RetrySpec(SaneRetryPolicies::defaultHttp());
    }
}
```

## Full Wiring Example (Symfony/Redis)

```php
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Store\Redis\RedisStateStore;
use Gohany\Circuitbreaker\Store\Redis\RedisHistoryStore;
use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakingPsr18Client;

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

## PDO (SQL) Storage Example

For persistent storage using any PDO-supported database (MySQL, PostgreSQL, SQLite, etc.).

```php
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Store\Pdo\PdoCircuitStateStore;
use Gohany\Circuitbreaker\Store\Pdo\PdoCircuitHistoryStore;
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

### Required SQL Schema (PDO)

You can find the complete SQL schema in `src/Store/Pdo/schema.sql`. 

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

## APCu (Shared Memory) Storage Example

For high-performance, single-server shared memory storage.

```php
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Store\Apcu\ApcuCircuitStateStore;
use Gohany\Circuitbreaker\Store\Apcu\ApcuCircuitHistoryStore;
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

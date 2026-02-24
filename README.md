![CI](https://github.com/Gohany/circuitbreaker/actions/workflows/ci.yml/badge.svg)
[![codecov](https://codecov.io/gh/Gohany/circuitbreaker/branch/main/graph/badge.svg)](https://codecov.io/gh/Gohany/circuitbreaker)
# Gohany Circuitbreaker

This project is a practical, general-purpose **circuit breaker** library for PHP (`>=7.4`).

But a circuit breaker is only the first chapter.

Over time, production systems grow a whole *resilience vocabulary*: circuit breaking, careful probing, retries, concurrency limits, fairness between callers, operational overrides, and observability. This repository implements that vocabulary as small, composable primitives.

If you only remember one sentence, remember this:

> **You are not “calling a dependency”. You are negotiating with it.**

The negotiation is driven by history (what happened recently), by current state (open/half-open/closed), by per-request context (tenant, endpoint, risk), and sometimes by operational reality (incident response overrides).

This README is intentionally long-form. It starts with the *concepts*, then gives an explicit *feature catalog*, and then expands into realistic usage examples.

---

## Table of contents

- [Why circuit breakers](#why-circuit-breakers)
- [Core concepts](#core-concepts)
- [Concepts (extended guide)](#concepts-extended-guide)
  - [Keys, dimensions, and context](#keys-dimensions-and-context)
  - [Policies, outcomes, and signals](#policies-outcomes-and-signals)
  - [State stores and history stores](#state-stores-and-history-stores)
  - [Probe gating (half-open concurrency)](#probe-gating-half-open-concurrency)
  - [Operational overrides](#operational-overrides)
  - [Retries (circuit-aware)](#retries-circuit-aware)
  - [Bulkheads (concurrency limits)](#bulkheads-concurrency-limits)
  - [Resilience pipeline](#resilience-pipeline)
  - [Observability](#observability)
  - [Sanity tooling](#sanity-tooling)
- [Feature catalog](#feature-catalog)
- [Installation](#installation)
- [Quickstart](#quickstart)
- [HTTP usage](#http-usage)
  - [Alternative: wrap a single call](#alternative-wrap-a-single-call)
  - [Choosing a circuit key](#choosing-a-circuit-key)
- [Default implementations & Examples](#default-implementations--examples)
- [Default HTTP policy](#default-http-policy)
- [Customizing default policies](#customizing-default-policies)
  - [Config subclasses (recommended)](#config-subclasses-recommended)
  - [Policy subclasses](#policy-subclasses)
  - [Fraud stays separate](#fraud-stays-separate)
- [Retry + Circuit Breaker](#retry--circuit-breaker)
  - [Circuit-aware retries (deep integration)](#circuit-aware-retries-deep-integration)
  - [Unified retryAfterMs calculation](#unified-retryafterms-calculation)
  - [Why composition order matters](#why-composition-order-matters)
  - [Sane retry defaults](#sane-retry-defaults)
  - [Idempotent vs non-idempotent retries](#idempotent-vs-non-idempotent-retries)
  - [Baking in retry policies](#baking-in-retry-policies)
- [Picking good numbers](#picking-good-numbers)
- [Testing recommendations](#testing-recommendations)
- [Exceptions](#exceptions)
- [FAQ](#faq)

---

## Why circuit breakers

Operations fail in messy ways:
- Timeouts and connection errors (Network)
- Resource exhaustion or locks (Database)
- Rate limits or internal errors (Third-party APIs)
- Slow, unstable performance (Brownouts)

If callers keep attempting a failing action during an outage, you typically get cascading failures:
- worker pool exhaustion
- queue backlogs
- rising latencies across unrelated components
- repeated retries that amplify load right when the system needs less load

A **circuit breaker** reduces blast radius by:
- **failing fast** when the action is known to be failing
- **waiting** a short time before attempting again
- **probing carefully** to detect recovery

---

## Core concepts

Circuit breakers typically have three states:

### `closed`
Normal mode: calls are allowed. Failures are tracked.

### `open`
Fail-fast mode: calls are blocked for `openDurationMs`.

### `half_open`
Probe mode after the open duration expires:
- if enough probes succeed → back to `closed`
- if enough probes fail → back to `open`

A key knob you’ll see in this project:

- `halfOpenFailuresToOpen`  
  In `half_open`, how many failures you tolerate before flipping back to `open`.

This prevents “flapping” (a dependency that briefly looks healthy but collapses again).

---

## Concepts (extended guide)

This section is the “novel” part: a guided walk through the moving parts, and *why* you might use each.

### Keys, dimensions, and context

The unit of protection is a `CircuitKey`.

- `CircuitKey::$name` is a stable circuit name like `"payments_http"` or `"database:write"`.
- `CircuitKey::$dimensions` let you slice the same circuit into many independent partitions (per tenant, provider, region, endpoint section, etc.).

The per-call “who/what/why” is a `CircuitContext`.

`CircuitKey` is *where state lives*. `CircuitContext` is *what you know about this request right now*.

In storage-backed implementations, a circuit key needs a stable identifier. This project provides `CircuitKey::id()` for that purpose.

### Policies, outcomes, and signals

`CircuitPolicyInterface` answers two questions:

1) **Should I allow this call right now?** (`decide(...)` → `PolicyDecision`)
2) **What should happen after we observe the outcome?** (`onOutcome(...)` → `TransitionPlan`)

The project’s policies operate on two kinds of inputs:

- A `CircuitSnapshot` (current state + recent history window)
- A `CircuitOutcome` (what happened: success/failure, optional signals, optional exception, duration)

Signals are how you convert messy reality into a vocabulary your policy understands.
For example: `timeout`, `http_5xx`, `fraud_suspected`, `rate_limited`.

The conversion is done by `OutcomeClassifierInterface`.

### State stores and history stores

There are two storage responsibilities:

- `CircuitStateStoreInterface` stores the current circuit state (`closed`, `open`, `half_open`) and metadata.
- `CircuitHistoryStoreInterface` records outcomes over time (counters and/or a time window).

This repo includes multiple storage backends under `src/Store/*`:

- In-memory stores for tests/local usage
- Redis stores for distributed systems
- PDO stores for SQL-backed persistence
- APCu stores for shared-memory on a single host

### Probe gating (half-open concurrency)

When a circuit transitions to `half_open`, you typically want to probe carefully:

- allow *some* requests through
- but not so many that a still-broken dependency is hammered

That is what the `ProbeGateInterface` does.

The core breaker (`Core\CircuitBreaker`) can acquire a probe gate permit when a decision indicates `requiresProbeGate`.
Implementations exist for in-memory, Redis, and PDO.

### Operational overrides

Production systems need a manual “steering wheel” during incidents.

Overrides let you force behaviour *without redeploying code*:

- force allow (temporary)
- force deny / force open (temporary)
- attach a reason and metadata

Overrides are implemented via `OverrideDeciderInterface` and the Redis implementation lives under `src/Override/Redis/*`.

### Retries (circuit-aware)

Retries without a circuit breaker can amplify an outage.
Retries without context can also hide persistent failures.

This project integrates with `gohany/rtry` under `src/Integration/Rtry/*` so retries can be:

- based on the same outcome classifier and signals
- stopped early when the circuit is clearly unhealthy
- reported back into circuit history

### Bulkheads (concurrency limits)

A circuit breaker decides *whether* you may call. A bulkhead decides *how many callers may call concurrently*.

This repo includes bulkheads under `src/Bulkhead/*`:

- `SemaphoreBulkhead` for local concurrency limiting
- `RedisPoolBulkhead` for distributed max-concurrency pools
- `RedisFairQueueBulkhead` for a distributed wait-queue with fairness and lane caps

The “fair queue” bulkhead is designed for shared resources like databases:

- a global cap across nodes
- per-lane caps (fixed/percent/weighted)
- queue scanning to avoid head-of-line blocking
- short-lived grants to avoid leaked capacity

### Resilience pipeline

When you start composing these primitives, you eventually want a single “do the safe thing” entry point.

`ResiliencePipeline` is a minimal middleware chain (see `src/Resilience/*`) that can wrap an operation with:

- circuit breaker middleware (`CircuitBreakerMiddleware`)
- retry middleware (`RtryRetryMiddleware`)
- bulkhead middleware (`BulkheadMiddleware`)

The pipeline is intentionally small: you can add/remove pieces without rewriting your business code.

### Observability

There are two observability styles in the repo:

- PSR-3 logging support inside the core circuit breaker (`Psr\Log\LoggerInterface`)
- a lightweight `EmitterInterface` for structured event emission (used by some middleware/bulkheads)

### Sanity tooling

There are scripts intended for *humans* and CI to validate wiring:

- `tools/circuit_sanity_check.php` (end-to-end sanity runner for HTTP-style policies)
- `bin/cb-sanity-fair-queue.sh` and `bin/cb-sanity-fair-queue-extended.sh` (Redis fair-queue bulkhead checks)

They are not required for runtime usage, but they are useful when you first integrate the library.

---

## Feature catalog

This is the explicit list of “what this project now does”, grouped by concept.

### Circuit breaker (core)

- Circuit states: `closed`, `open`, `half_open` (`Consts\CircuitStateMode`)
- Decisions (`Core\CircuitDecision`) and exceptions (`Exception\CircuitDeniedException`)
- Pluggable decision logic via `Policy\CircuitPolicyInterface`
- Pluggable classification via `Policy\OutcomeClassifierInterface`
- Records outcomes after execution and applies state transition plans (`Policy\TransitionPlan`)

### HTTP defaults (PSR-18)

- Single-circuit PSR-18 decorator: `Defaults\Http\CircuitBreakingPsr18Client`
- Multi-circuit PSR-18 decorator (multiple circuits per request): `Defaults\Http\MultiCircuitBreakingPsr18Client`
- Key-building strategies:
  - `Defaults\Http\DefaultHttpCircuitBuilder`
  - `Defaults\Http\PathSectionHttpCircuitBuilder`
  - `Defaults\Http\CircuitBreakerKeyFactory` and “pieces” (`Defaults\Http\Pieces\*`)

### Bulkheads

- Local concurrency limit: `Bulkhead\SemaphoreBulkhead`
- Redis distributed pool cap: `Bulkhead\RedisPoolBulkhead`
- Redis distributed fair queue with lane policies: `Bulkhead\RedisFairQueueBulkhead`, `Bulkhead\PoolPolicy`, `Bulkhead\LanePolicy`

### Stores

- In-memory stores for local usage/tests (`Store\InMemory*`)
- Redis stores (`Store\Redis\*`) with `RedisKeyBuilder`
- PDO stores (`Store\Pdo\*`) for SQL persistence
- APCu store (`Store\Apcu\*`) for shared memory

### Probe gating

- Probe gate interface (`Store\ProbeGateInterface`) + result/config types
- Implementations for in-memory, Redis, and PDO

### Operational override & administration (Redis)

- Override store + decider (`Override\Redis\RedisOverrideStore`, `Override\Redis\RedisOverrideDecider`)
- Admin operations like forgiving/resetting history (`Override\Redis\RedisCircuitAdmin`)

### Retry integration

- Circuit-aware retry execution via `Integration\Rtry\RtryRetryExecutor`
- Retry spec support via `Integration\Rtry\RetrySpec` and `RetrySpecProviderInterface`
- Sane defaults via `Defaults\Rtry\SaneRetryPolicies`

### Resilience pipeline

- `Resilience\ResiliencePipeline` and middlewares:
  - `Resilience\CircuitBreakerMiddleware`
  - `Resilience\RtryRetryMiddleware`
  - `Resilience\BulkheadMiddleware`

### Observability

- PSR-3 logging hooks in `Core\CircuitBreaker`
- `Observability\EmitterInterface` and `Observability\NullEmitter`

---

## Installation

```bash
composer require gohany/circuitbreaker
```

---

## Quickstart

At minimum you:
1) Create a policy (which defines what a "failure" is and when to trip)
2) Create a circuit breaker (with a state store)
3) Wrap your risky operation

### Basic Example (Any Operation)

```php
use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;

// 1. Choose a policy
$cfg = new \App\Circuit\Config\DefaultHttpConfig();
$policy = new DefaultHttpCircuitPolicy($cfg);

// 2. Setup the breaker
$breaker = new CircuitBreaker($stateStore, $historyStore, $policy, $classifier);

// 3. Execute your action
$result = $breaker->execute(
    new CircuitKey('my-action-key'), 
    new CircuitContext(), 
    function () {
        // Your risky logic here: DB, API, internal call, etc.
        return do_something_risky();
    }
);
```

Notes:
- The “store” can be in-memory (single-process), **Redis** (distributed), **PDO/SQL** (persistent), or **APCu** (single-server shared memory).
- The circuit key determines what shares a fate; see [Choosing a circuit key](#choosing-a-circuit-key).

---

## HTTP usage

Because HTTP is the most common use case, we provide a PSR-18 client decorator, multi-circuit coordination, and composable key-building helpers.

For detailed usage patterns, including the **PSR-18 HTTP Client Decorator**, custom key/context building, and full wiring examples, please refer to:

👉 **[examples.md](examples.md)**

Recommended starting points:
- `CircuitBreakingPsr18Client` (single circuit per request)
  - See: `examples.md` → [HTTP: single-circuit PSR-18 decorator](examples.md#http-single-circuit-psr-18-decorator)
- `MultiCircuitBreakingPsr18Client` (ordered list of circuits per request)
  - See: `examples.md` → [HTTP: multiple circuits per request](examples.md#http-multiple-circuits-per-request)
- `CircuitBreakerKeyFactory` + key pieces (deterministic, order-independent key composition)
  - See: `examples.md` → [HTTP key composition](examples.md#http-key-composition-circuitbreakerkeyfactory--pieces)
- Dual-key fraud pattern (`recordOutcome(...)`)
  - See: `examples.md` → [Pattern: dual-key reliability + tenant fraud lockout](examples.md#pattern-dual-key-reliability--tenant-fraud-lockout)

---

### Alternative: wrap a single call

For one-off risky operations:

```php
$result = $breaker->execute($key, $context, function () use ($fraudClient, $payload) {
    return $fraudClient->score($payload);
});
```

---

### Choosing a circuit key

A circuit key answers: **what should share a fate?**

Good defaults:

- **Service + capability** (recommended)
    - `payments:charges`
    - `payments:refunds`
    - `fraud:score`
    - `crm:reads`
    - `db:users:write`

- **Per host/resource** (simple)
    - `http:api.vendor.com`
    - `redis:main`
    - `s3:bucket-name`

- **Per endpoint/path group** (more granular)
    - `vendorx:/v1/charges`
    - `vendorx:/v1/customers`

Rule of thumb:
- Start with **service + capability**
- Split further only when different endpoints have different failure modes or SLOs

---

## Default implementations & Examples

To get started quickly, this library provides several "sane" defaults:

- **PSR-18 HTTP Decorator**: `CircuitBreakingPsr18Client` wraps any PSR-18 client to add circuit breaking automatically.
- **Sane Retry Policies**: `SaneRetryPolicies` provides pre-configured `rtry` policies for idempotent (`defaultHttp`) and non-idempotent (`conservativeWrite`) operations.

For detailed usage patterns and wiring examples, see [examples.md](examples.md).

---

## Default HTTP policy

This project ships an HTTP-oriented base policy/config:

- `Gohany\Circuitbreaker\Policy\Http\AbstractHttpCircuitPolicy`
- `Gohany\Circuitbreaker\Policy\Http\HttpCircuitPolicyConfig`

A typical HTTP policy treats these as failures:
- network exceptions (DNS, connect timeout, read timeout)
- upstream `5xx`

Often **not** counted as dependency failures:
- most `4xx` (they usually mean caller error, not vendor outage)

Key knobs:
- `openDurationMs`
- `halfOpenFailuresToOpen`

---

## Customizing default policies

### Config subclasses (recommended)

This project intentionally avoids “constructor-loop overrides.” Instead, prefer small config subclasses with explicit defaults.

Example:

```php
namespace App\Circuit\Config;

use Gohany\Circuitbreaker\Policy\Http\HttpCircuitPolicyConfig;

final class DefaultHttpConfig extends HttpCircuitPolicyConfig
{
    public int $openDurationMs = 15_000;
    public int $halfOpenFailuresToOpen = 1;
}

final class PaymentsHttpConfig extends HttpCircuitPolicyConfig
{
    public int $openDurationMs = 60_000;
    public int $halfOpenFailuresToOpen = 2;
}
```

Usage:

```php
use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;

$policy = new DefaultHttpCircuitPolicy(new \App\Circuit\Config\PaymentsHttpConfig());
```

Why this is good:
- No hidden runtime override behavior
- Defaults are easy to review and diff
- Service-specific posture is explicit and testable

---

### Policy subclasses

Use a policy subclass when “what counts as failure” differs.

Example intent:
- Treat `429` as a failure (open quickly if the vendor is rate-limiting hard)
- Treat `409` as non-failure (expected conflict)

```php
use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;
use Gohany\Circuitbreaker\Policy\Http\HttpCircuitPolicyConfig;

final class VendorXHttpConfig extends HttpCircuitPolicyConfig
{
    /**
     * Example intent:
     * - Treat `429` as a failure signal (open quickly if the vendor is rate-limiting hard)
     * - Treat `409` as non-failure (expected conflict)
     */
    public array $failureSignals = [
        'timeout',
        'connect_error',
        'dns',
        'http_5xx',
        'http_429',
    ];
}

$policy = new DefaultHttpCircuitPolicy(new VendorXHttpConfig());
```

The pattern is: centralize classification in your `OutcomeClassifierInterface` and keep per-service differences in config.

---

### Fraud stays separate

Fraud vendors often have domain semantics (scores, review/hold flows) that do not belong in the base HTTP rules.

Keep layers clean:
- HTTP base policy: status codes, timeouts, transport failures
- Fraud policy: domain logic, score thresholds, hold/review behavior

You still circuit-break fraud calls, you just avoid mixing domain rules into generic HTTP logic:

```php
$breaker->execute($key, $context, function () use ($fraudClient, $payload) {
    return $fraudClient->score($payload);
});
```

---

## Retry + Circuit Breaker

Retries and circuit breakers solve different problems:

- **Retry**: “this might work if I try again”
- **Circuit breaker**: “stop hammering; dependency is unhealthy”

Used incorrectly, retries amplify outages. Used correctly, retries reduce tail latency and smooth over transient blips.

### Circuit-aware retries (deep integration)

This project integrates deeply with `gohany/rtry` to ensure retries are safe.

When using the `RtryRetryExecutor`, it automatically applies a **CompositeDecider** that checks:
1. **Outcome classification**: Does the circuit breaker’s `OutcomeClassifierInterface` think the error is a `transient_failure`?
2. **Circuit health**: Is the circuit still `allowed` according to the current state and policies (e.g., fraud overrides, manual circuit trips)?

If the circuit is denied **mid-retry** (e.g., a tenant is blocked for fraud while a network request is retrying), the executor will stop immediately and propagate the failure.

#### Extension points:
- `ClassifierRetryDecider`: Uses your existing classification logic for retry decisions.
- `CircuitHealthRetryDecider`: Keeps the retry loop in sync with the global circuit state.
- `CompositeDecider`: Automatically chains your existing `rtry` deciders with the circuit-aware logic (using AND logic).

### Easy expansion from rtry to Circuit Breaker

If you are already using `gohany/rtry`, you can bring your existing policies and deciders into the circuit breaker seamlessly. The `RtryRetryExecutor` will respect your custom deciders:

```php
$myRtryPolicy = new \Gohany\Rtry\Impl\RtryPolicy();
$myRtryPolicy->setRetryDecider(new MyCustomDecider());

$breaker = new CircuitBreaker(
    // ...,
    retryExecutor: new RtryRetryExecutor($classifier),
    retryPolicyOrSpec: $myRtryPolicy
);
```

The breaker will only retry if:
1. Your `MyCustomDecider` says YES.
2. The circuit breaker's classifier says the error is `transient_failure`.
3. The circuit is still healthy (not opened/tripped mid-retry).

### Unified retryAfterMs calculation

When a circuit is **open**, the breaker tells the caller how long to wait via `retryAfterMs`. To ensure consistency between your circuit breaker and retry strategies, `AbstractHttpCircuitPolicy` can dynamically calculate this value using your `rtry` policies.

By implementing `RetrySpecProviderInterface` in your policy (or using the default provided hooks), the circuit breaker will:
1. Check if the current policy has a `RetrySpec` for the circuit.
2. If so, it takes the **maximum** of:
   - The remaining time until the circuit technically "expires" its open state.
   - The `startAfterMs` delay defined in your `rtry` policy.

This prevents the circuit breaker from allowing a "half-open" probe attempt earlier than your retry policy would even allow the first retry.

### Why composition order matters

Recommended composition:

> **Circuit breaker wraps the overall operation. Retries happen inside.**  
> The breaker records *one* success/failure for the operation, not for each attempt.

Conceptually:

```php
$breaker->execute($key, $context, function () use ($retryer) {
    return $retryer->run(function () {
        return $this->doOneAttempt();
    });
});
```

Avoid:
- retry **outside** the circuit breaker (you keep calling even when open)
- counting each retry attempt as a breaker failure (opens too fast)

Also: when the circuit is **open**, do **not** retry. Fail fast.

---

### Sane retry defaults

A good default posture for **idempotent** HTTP operations:

- `maxAttempts`: **3**
- exponential backoff starting around **100ms**
- `maxDelay`: **2s**
- jitter: **yes** (prevents synchronized retry storms)
- retry on:
    - connect/read timeouts, DNS, transport errors
    - `5xx`
    - optionally `429` (prefer honoring `Retry-After` if present)

Do **not** retry most `4xx`.

---

### Idempotent vs non-idempotent retries

#### Idempotent example: GET (safe to retry)

```php
$resp = $breaker->execute($key, $context, function () use ($retryer, $http) {
    return $retryer->run(function () use ($http) {
        return $http->request('GET', 'https://crm.example.com/v1/customers/123');
    });
});
```

#### Non-idempotent example: POST (dangerous to retry)

Only retry if you have a strong idempotency mechanism (idempotency key, request token, etc.).

```php
$key = bin2hex(random_bytes(16));

$resp = $breaker->execute($key, $context, function () use ($retryer, $http, $key) {
    return $retryer->run(function () use ($http, $key) {
        return $http->request('POST', 'https://pay.example.com/v1/charges', [
            'headers' => ['Idempotency-Key' => $key],
            'json' => [/* ... */],
        ]);
    });
});
```

Conservative posture for writes:
- `maxAttempts`: **2**
- retry only on clear transport failures
- be careful retrying on read timeouts (request may have succeeded server-side)

---

### Baking in retry policies

If you want “sane retry policies” baked into this project (not required, but often desirable), keep it minimal and composable.

Suggested building blocks:

1) **RetryPolicy** (numbers + backoff behavior)
2) **RetryDecider** (what is retryable?)
3) **Retryer** (executes attempts + sleeps)

Suggested defaults to ship:

- `DefaultHttpRetryPolicy`
    - 3 attempts, expo backoff, jitter, max delay 2s
- `ConservativeWriteRetryPolicy`
    - 2 attempts, transport-only (or transport + 502/503/504 depending on your appetite)
- `DefaultHttpRetryDecider`
    - retry on network/timeout + 5xx (+ optional 429)

Then usage looks like:

```php
$retryer = new Retryer(
    new DefaultHttpRetryPolicy(),
    new DefaultHttpRetryDecider(),
);

$response = $breaker->execute($key, $context, function () use ($retryer, $http) {
    return $retryer->run(function () use ($http) {
        return $http->request('GET', 'https://api.vendorx.com/v1/resource');
    });
});
```

Implementation notes:
- Ensure jitter is used to prevent thundering herds.
- If you support `Retry-After`, honor it (bounded by max delay).
- In `half_open`, consider reducing attempts (e.g., 1–2) to avoid overloading a recovering service.

---

## Picking good numbers

Starting points:

### General external HTTP dependencies
- `openDurationMs`: **15–30s**
- `halfOpenFailuresToOpen`: **1–2**
- retries: **3 attempts**, jittered expo backoff

### Payments / fraud vendors
- `openDurationMs`: **30–90s**
- `halfOpenFailuresToOpen`: **2**
- retries: **2 attempts**, conservative

### Internal services
- `openDurationMs`: **5–15s**
- `halfOpenFailuresToOpen`: **1**
- retries: **3 attempts** can be okay, still jittered

If you’re unsure: start conservative and tune with real metrics.

---

## Testing recommendations

At minimum, validate these transitions:

1) `closed → open`
- repeated failures reach threshold → breaker opens

2) `open` blocks calls
- before `openDurationMs` elapses → fail fast

3) `open → half_open`
- after `openDurationMs` → allow probes

4) half-open failure threshold
- if `halfOpenFailuresToOpen = 2`, the **first** half-open failure should *not* immediately open
- only after the **second** half-open failure should it flip back to `open`

5) `half_open → closed`
- sufficient success closes the circuit

Also test classification rules:
- 500 counts as failure
- 400 does not (unless intentionally overridden)
- timeouts/transport exceptions count as failures

---

### Exceptions

The library uses a hierarchy of exceptions that all extend `\RuntimeException`, making them easy to catch while remaining compatible with standard PHP error handling.

- `CircuitBreakerException`: The base exception for all circuit breaker related errors.
- `CircuitDeniedException`: Thrown when a call is blocked because the circuit is `OPEN` or an override decider denied it.
    - `getReason()`: Returns the reason for denial.
    - `getRetryAfterMs()`: Returns the suggested wait time in milliseconds.
- `ProbeGateBlockedException`: Thrown when the circuit is `HALF_OPEN` but the maximum number of concurrent probe attempts has been reached.
    - `getRetryAfterMs()`: Returns the suggested wait time until the next probe might be allowed.

```php
use Gohany\Circuitbreaker\Exception\CircuitDeniedException;
use Gohany\Circuitbreaker\Exception\ProbeGateBlockedException;

try {
    $result = $breaker->execute($key, $context, $operation);
} catch (CircuitDeniedException $e) {
    // Handle "fail-fast" by showing a cached result or a friendly error
    echo "Blocked: " . $e->getReason() . ". Try again in " . $e->getRetryAfterMs() . "ms";
} catch (ProbeGateBlockedException $e) {
    // Too many probes in flight
    echo "Probing in progress. Try again in " . $e->getRetryAfterMs() . "ms";
}
```

---

## FAQ

### Should I retry when the circuit is open?
No. If the circuit is open, fail fast immediately.

### Should circuits be per-host or per-endpoint?
Start with **service + capability** keys (`payments:charges`). Split further only if needed.

### Where should I wrap the breaker?
As close to the dependency boundary as possible:
- HTTP client decorator
- SDK client wrapper
- service method that calls the dependency

### How do I keep fraud separate?
Use separate keys/config/policies for fraud: `fraud:*`. Don’t put domain semantics into base HTTP rules.

---
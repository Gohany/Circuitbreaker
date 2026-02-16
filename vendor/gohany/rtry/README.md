# Rtry — A pragmatic PHP retry & backoff toolkit 
[![tests](https://github.com/Gohany/Rtry/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/Gohany/Rtry/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/Gohany/Rtry/branch/master/graph/badge.svg)](https://codecov.io/gh/Gohany/Rtry)

Robust, composable retries for HTTP, RPC, queues, and DB work. Rtry focuses on **clear policy specs**, **safe defaults**, and **first‑class support** for jitter, hedging, deadlines, and rate‑limit headers.

> Namespaces used in this repo include `Gohany\Rtry` (implementation & policy) and `Gohany\Retry` (interfaces / runtime). The library targets PHP **7.4+** and works on PHP 8.x.

---

## Table of contents

- [Features](#features)
- [Install](#install)
- [Quick start](#quick-start)
- [Core concepts](#core-concepts)
    - [Retry runtime](#retry-runtime)
    - [Retry policy](#retry-policy)
    - [Delays & sequences](#delays--sequences)
    - [Jitter](#jitter)
    - [Hedging](#hedging)
    - [Deadlines vs. per‑attempt timeout](#deadlines-vs-perattempt-timeout)
    - [Following rate‑limit headers](#following-rate-limit-headers)
    - [Deciders](#deciders)
    - [Rule‑based failure classification](#rule-based-failure-classification)
- [Array spec: authoring policies concisely](#array-spec-authoring-policies-concisely)
- [Hooks](#hooks)
- [Duration tokens](#duration-tokens)
- [Testing & code coverage](#testing--code-coverage)
- [CI badges](#ci-badges)
- [Examples](#examples)
- [Contributing](#contributing)
- [License](#license)

---

## Features

- **Simple API**: `Retry::try(callable $op, RetryPolicyInterface $policy)`
- **Expressive policies**: compact array spec (`a`, `sa`, `dl`, `cap`, `j`, `h`, `seq`, `fh`) or factory builders
- **Backoff modes** with **jitter** (full / plus‑minus / none)
- **Hedging** (concurrent lanes with stagger & cancel policy)
- **Global deadlines** and **per‑attempt timeouts**
- **Follow server hints**: `Retry-After`, `RateLimit-Reset(-After)`, etc.
- **Deciders & rules** to control retry on specific failures / tags / status families
- **PSR friendly**: `Psr\Clock\ClockInterface`, `Psr\Log\LoggerInterface`, `Psr\Http\Message\ResponseInterface`
- **Thorough tests** and examples

---

## Install

Via Composer:

```bash
composer require Gohany/Rtry
```

---

## Quick start

```php
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;

// Build a policy from a string spec:
$factory = new RtryPolicyFactory();
$policy  = $factory->fromSpec('rtry:a=5;d=200;mode=exp;b=2;cap=8s;j=100@full;on=5xx,ETIMEDOUT');

$retry = new Retry(); // uses default Clock, Sleeper, Logger
$result = $retry->try(function () {
    // Your flaky operation (HTTP call, DB query, etc)
    return 'ok';
}, $policy);
```

---

## Core concepts

### Retry runtime

`Gohany\Rtry\Impl\Retry` orchestrates attempts:

- asks the policy for **startAfter**, **nextDelayMs(attempt)**, **attemptTimeoutMs**, **deadlineBudgetMs**, **capMs**, **backoffMode**
- applies **jitter** and **cap**
- optionally **follows headers** using the failure classifier (see below)
- respects **deadlines** and **per‑attempt timeouts**
- emits hooks: **betweenAttempts**, **onGiveUp**

### Retry policy

Implemented by `RetryPolicyInterface` (base) and enriched by `RtryPolicyInterface` (jitter/hedge/backoff/classifier). Build via `RtryPolicyFactory` from an array spec or use the underlying parts (e.g., `Attempts`, `Delay`, `Sequence`, `Jitter`, `Hedge`).

### Delays & sequences

Use a fixed backoff, exponential, or an explicit **sequence**.

```php
use Gohany\Rtry\Impl\Parts\Sequence;

// Accepts multiple forms:
Sequence::make('50,100,1.5s*');
Sequence::make('(50,100ms,1.5s*)');
Sequence::make('seq=(50,100,1.5s,*)');

// Canonical string form:
(string) Sequence::make('50,100,1.5s*'); // "seq=(50,100,1.5s*)"

// '*' means repeat the last delay indefinitely.
```

### Jitter

Jitter smooths out stampedes by randomizing delays.

`JitterSpecInterface`:
- `mode()` — `'full' | 'pm' | 'none'` (pm = plus/minus)
- `percent()` — 0..1 (for plus/minus), or `null`
- `windowMs()` — absolute window (for plus/minus), or `null`
- `apply(int $nominalDelayMs, ?int $seed = null): int`

**Spec examples** for `'j'`:

```
'50@full', '100ms@full', '10m@full', '20%@full',
'50@pm',   '100ms@pm',   '10m@pm',   '20%@pm',
```

### Hedging

Run multiple lanes concurrently with a **stagger delay**, cancel others on first success/completion.

`HedgeSpecInterface`:
- `getLanes(): int`
- `getStaggerDelayMs(): int`
- `getCancelPolicy(): int` (`CANCEL_ON_FIRST_SUCCESS=0` or `CANCEL_ON_FIRST_COMPLETION=1`)

**Spec examples** for `'h'`:

```
'2@100ms', '2@10s', '2@1m', '2@1h',
'3@100&1'  // lanes=3, stagger=100ms, cancel-on-first-completion
```

### Deadlines vs per‑attempt timeout

- **Global deadline** (`dl`) sets a total budget for the whole retry run (sleep time counts).
- **Per‑attempt timeout** (`timeout`) limits a single attempt duration.

If there’s not enough budget for the next delay, `Retry` **gives up** and triggers the `onGiveUp` hook.

### Following rate limit headers

With `fh=1` (followHeaders=true), `Retry` consults the failure classifier to extract hints from responses:

- `Retry-After: 1` → minimum next delay = **1000ms**
- `Retry-After: Wed, 21 Oct 2015 07:28:00 GMT` → **notBeforeUnixMs**
- `RateLimit-Reset(-After)`, `X-RateLimit-Reset(-After)`, `X-RateLimit-Reset-MS` are recognized

The `RateLimitBackoffRule` returns `FailureMetadata` with `minNextDelayMs` or `notBeforeUnixMs`, and tags like `RATE_LIMITED`.

### Deciders

Control *whether* to retry:

- `AlwaysRetryDecider`
- `OnTokensDecider` — retry on status codes (`429`, `503`), families (`4xx`, `5xx`), or named tags (`RETRY-AFTER`, `TRANSIENT`)
- `CompositeDecider` — OR composition (short‑circuit)

### Rule‑based failure classification

`RuleBasedFailureClassifier` applies **rules** to a `Throwable` to derive **status**, **tags**, **context patch**, **headers**, and **backoff hints**:

- `InstanceOfRule` — match on exception class
- `MessageRegexRule` — match on message
- `MethodStatusRule` — pull status from `getResponse(): ResponseInterface` or `getCode()`
- `RateLimitBackoffRule` — parse standard rate‑limit headers

Derived tags include `ETIMEDOUT`, `ECONNRESET`, `NETWORK_ERROR`, and DB `DEADLOCK` heuristics.

---

## Rtry spec: authoring policies concisely

You can configure multiple candidate values per key (the factory will validate & choose). Common keys:

| Key   | Meaning                 | Examples                                                                                      |
|-------|-------------------------|-----------------------------------------------------------------------------------------------|
| `a`   | attempts                | `5`, `[5,10]`                                                                                 |
| `sa`  | start after             | `200`, `'200ms'`, `'200s'`, `'2m'`, `'1h'`                                                    |
| `dl`  | deadline budget         | `200`, `'200ms'`, `'2s'`, `'1m'`                                                              |
| `cap` | cap for attempts        | `'200ms'`, `'2m'`, `'1h'`                                                                     |
| `j`   | jitter                  | `'20%@pm'`, `'100ms@pm'`, `'50@full'`, `'1.5m@full'`                                          |
| `h`   | hedge                   | `'2@100ms'`, `'3@100&1'`                                                                      |
| `seq` | explicit delay sequence | `'(50,100ms,1.5s*)'`, `'seq=(50,100,1.5s,*)'`, `'50,100,1.5s*'` (canonical prints as `seq=…`) |
| `fh`  | follow headers (bool)   | `true` / `false`                                                                              |
| `b`   | exponential base        | `2`, `2.5`                                                                                    |
| `on`  | on tags                 | `5xx`, `429`, `ratelimit`, `throttle`, `4xx`                                                   |

> **Tip:** numbers without unit default to **milliseconds**. `1.5m` and `1.5s` are supported; decimals for `h/m/s` are allowed.

---

## Hooks

```php
$retry->setBetweenAttemptsHook(function (
    \Gohany\Retry\AttemptContextInterface $ctx,
    \Gohany\Retry\AttemptOutcomeInterface $outcome,
    \Gohany\Retry\RetryPolicyInterface $policy,
    int $sleepMs,
    array $lastHeaders
) {
    // observe sleep decision, metrics, logging, etc.
});

$retry->setOnGiveUpHook(function ($ctx, $outcome, $policy, $headers) {
    // after the final failure before giving up
});
```

---

## Duration tokens

- Accepted suffixes: `ms`, `s`, `m`, `h`
- Bare numbers = `ms`
- Decimals supported for `s/m/h` (e.g., `1.5s`, `2.5m`)
- Canonical formatting prefers compact, human‑readable units; sub‑second prints as bare `ms`

---

## Testing & code coverage

Run the test suite:

```bash
composer install
composer test
```

**Note:** The test suite is configured to generate code coverage reports. If you encounter "No code coverage driver available", you'll need to install either Xdebug or PCOV:

### Installing a code coverage driver

**Option 1: PCOV (recommended for performance)**
```bash
pecl install pcov
```
Then add to your `php.ini`:
```ini
extension=pcov.so
```

**Option 2: Xdebug**
```bash
pecl install xdebug
```
Then add to your `php.ini`:
```ini
zend_extension=xdebug.so
xdebug.mode=coverage
```

**Run tests without coverage:**
```bash
composer test -- --no-coverage
```

---

## Examples

### Retry with sequence + jitter + follow headers

```php
$spec = 'rtry:a5;seq=50,100,200,400,800*;j=20%@pm';
$policy = (new RtryPolicyFactory())->fromSpec($spec);
$result = (new Retry())->try(fn() => $client->get($url), $policy);
```

### Hedged requests

```php
$policy = (new RtryPolicyFactory())->fromSpec('rtry:a=1;h=3@75');
$retry = new Retry();
$resp  = $retry->try(fn() => $client->get($url), $policy);
```

### Deciders: retry on 4xx/5xx or named tags

```php
use Gohany\Rtry\Impl\Deciders\CompositeDecider;
use Gohany\Rtry\Impl\Deciders\OnTokensDecider;

$spec = 'rtry:a5;seq=50,100*;j=20%@pm;on=4xx,5xx,NETWORK_ERROR,RATE_LIMITED';
$policy = (new RtryPolicyFactory())->fromSpec($spec);
$retry = new Retry();
$resp  = $retry->try(fn() => $client->get($url), $policy);
```

---

## Contributing

- Run `composer test` (or `vendor/bin/phpunit`) before PRs
- Keep new rules/deciders covered with AAA‑style tests
- When adding new duration tokens or headers, document them here and in tests

---

## License

MIT (see `LICENSE`).


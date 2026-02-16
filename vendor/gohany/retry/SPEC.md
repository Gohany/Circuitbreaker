# Retry Interfaces & RTRY Policy — Draft SPEC (v1)

> **Status:** Personal draft. This document describes a proposed set of retry interfaces and a compact, one‑line retry policy string (“RTRY”). It is intended to seed discussion and working‑group formation.

## 1. Abstract

This SPEC defines a small set of interfaces for retrying idempotent or compensatable operations in PHP, along with a portable, single‑column retry policy string (`rtry:`). The goal is to standardize how libraries and frameworks express backoff, jitter, deadlines, and retryability decisions without prescribing any specific transport or framework.

## 2. Scope

- **In scope**
    - A minimal interface for executing an operation under a retry policy (`RetryerInterface::try()`).
    - A policy interface (`RetryPolicyInterface`) that describes attempts, delays, jitter, deadlines, and hedging hints.
    - A decider interface to decide retry vs. stop based on the outcome (`RetryDeciderInterface`).
    - A compact retry configuration string (`rtry:`) and recommended canonicalization.
    - Optional integration points with PSR‑3 (logs), PSR‑14 (events), PSR‑20 (clock).

- **Out of scope**
    - Cancellation semantics for long‑running user callables (PHP cannot forcibly cancel synchronous code).
    - Concurrency/hedging runtime guarantees (left to implementations/environments).
    - Transport‑specific guidance (HTTP, DB, queues) beyond generic status/tag mapping.

## 3. Terminology

- **Attempt**: One invocation of the user‑provided callable.
- **Backoff**: Time waited between attempts.
- **Jitter**: Randomization applied to reduce synchronization and thundering herds.
- **Deadline**: Upper bound on total wall‑clock time budget.
- **Hedging**: Launching speculative, parallel attempts to reduce tail latency (idempotent operations only).

## 4. Design Overview

Implementations provide one primary entrypoint:

```php
public function try(callable $operation, RetryPolicyInterface $policy, array $context = []);
```

- The **policy** supplies delays, jitter, and limits.
- The **decider** (part of the policy) determines retry vs. stop for each outcome.
- The **attempt context** exposes attempt #, elapsed time, and remaining budget to the callable.
- The **outcome** wraps either a result or a Throwable for the decider’s inspection.

The **RTRY** string allows policies to be stored in a single DB column, config value, or message header and compiled by a factory into a `RetryPolicyInterface` at runtime.

## 5. Interfaces (summary)

### 5.1 RetryerInterface

- Method: `try(callable $operation, RetryPolicyInterface $policy, array $context = [])` → mixed
- Throws the last error if attempts are exhausted.

### 5.2 RetryPolicyInterface

- `attempts(): int` — total attempts, including the first.
- `startAfterMs(): int` — delay before attempt #1.
- `nominalDelayMs(int $attemptNumber): int` — nominal delay (before jitter) for attempt *n* (≥2).
- `jitter(): JitterSpecInterface` — how to randomize nominal delays.
- `attemptTimeoutMs(): ?int` — per‑attempt timeout hint (advisory).
- `deadlineBudgetMs(): ?int` — overall budget; no attempt may start after this.
- Hedging hints: `hedgeEnabled()`, `hedgeExtra()`, `hedgeDelayMs()`.
- `decider(): RetryDeciderInterface` — policy’s retry decision strategy.
- `canonicalSpec(): string` — normalized `rtry:` string for logging/telemetry.

### 5.3 AttemptContextInterface

- Attempt number, max attempts, scheduled delay, elapsed since first, remaining budget, and the free‑form `context` array.

### 5.4 AttemptOutcomeInterface

- `isSuccess()`, `result()`, `error()`, optional `statusCode()`, and string `tags()` for decider mapping.

### 5.5 RetryPolicyFactoryInterface

- `fromSpec(string $spec): RetryPolicyInterface` — parse/validate `rtry:` and produce a policy.

## 6. RTRY String (normative)

A semicolon‑delimited `key=value` list with `rtry:` prefix:

```
rtry:a=<int>;d=<duration>;mode=<exp|lin|seq>;[b=<float>|inc=<duration>|seq=(<dur>[,<dur>...][,*])];
cap=<duration>;j=<percent|duration>;jmode=<full|pm|none>;t=<duration>;dl=<duration>;
on=<codes>;sa=<duration>;hedge=<n>@<delay>
```

- **a** attempts (≥1).
- **d** base delay (duration units: `ms|s|m|h`).
- **mode** = `exp` (default) | `lin` | `seq`.
- **b** exponential factor (≥1).
- **inc** linear increment (duration).
- **seq=(...)` explicit sequence; optional trailing `*` repeats last (hint).
- **cap** max per‑attempt delay (hint to implementations).
- **j/jmode** jitter configuration (`full`, `pm`, `none`).
- **t** per‑attempt timeout (advisory).
- **dl** total deadline budget.
- **on** adapter‑defined retryable tokens (e.g., `5xx,429,ETIMEDOUT`).
- **sa** start‑after before attempt #1.
- **hedge=n@delay** enables hedging hints.

**Canonical key order** for storage:  
`a; d; mode; [b|inc|seq]; cap; j; jmode; t; dl; on; sa; hedge`

### 6.1 Durations

Regex: `^([0-9]+(?:\.[0-9]+)?)(ms|s|m|h)$`

### 6.2 Jitter

- `full`: uniform `[0, delay]` (recommended).
- `pm`: plus/minus (`j=20%` or `j=200ms`).
- `none`: no jitter.

### 6.3 Validation

- Require: `a` and `mode` (default exp), `d` for `exp|lin`, `inc` for `lin`, `seq` for `seq`.
- Reject unknown/duplicate keys; durations and percents must match regexes.
- Parsers should emit a **canonical** string for observability and caching.

## 7. Interoperability and PSRs

- **PSR‑3**: Implementations MAY log attempt outcomes; the SPEC does not require a logger dependency.
- **PSR‑14**: Implementations MAY dispatch AttemptStarted/AttemptFailed/AttemptSucceeded/GiveUp events.
- **PSR‑20**: Implementations SHOULD use `ClockInterface` for time and expose a `SleeperInterface` for testability.
- **PSR‑18/7/16**: Compatible as a wrapping layer around HTTP clients, message buses, caches, etc.

## 8. Error Handling & Semantics

- The retryer rethrows the **last** Throwable when attempts are exhausted or budget is gone.
- Per‑attempt timeouts are advisory: PHP cannot force‑cancel synchronous user code.
- Hedging requires idempotency or explicit idempotency keys at the call site.

## 9. Security Considerations

- Use **jitter** to avoid coordinated retries that could be abused for traffic amplification.
- Protect webhook/HTTP actions with HMAC or mTLS; retries can magnify side‑effects.
- Enforce **idempotency** when using hedging; duplicate effects may occur.

## 10. Versioning

- This document is **v1**. If the wire/string format changes incompatibly, bump the prefix to `rtry2:`.

## 11. Prior Art

- Exponential backoff with full jitter (industry practice).
- Retry policies and decorators in major clients (HTTP, gRPC, AWS SDKs).
- PSR‑20 for time abstractions.

## 12. IPR & License

This specification text is © 2025 Gregory Riley and contributors. Licensed under **Creative Commons Attribution 4.0 (CC‑BY‑4.0)**.

Reference code (interfaces and examples) is licensed under **MIT** unless noted otherwise.

**Attribution/NOTICE:**

> © 2025 Gregory Riley and contributors. Licensed under Creative Commons Attribution 4.0 (CC BY 4.0). Changes may have been made. License: https://creativecommons.org/licenses/by/4.0/


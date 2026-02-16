# Retry Interfaces & RTRY Policy (Draft)

A tiny set of PHP interfaces for standardized retry behavior, plus a compact `rtry:` string you can store in one column and compile at runtime.

## Features

- **Portable API**: `RetryerInterface::try(callable $op, RetryPolicyInterface $policy, array $context = [])`.
- **Single‑column policy** with `rtry:` strings (backoff, jitter, deadlines, hedging hints).
- **Testable**: `ClockInterface` + `SleeperInterface` → no real sleeps in unit tests.
- **Deciders**: pluggable logic to map outcomes to retry/stop decisions.

## Quick start

```php
use Psr\Retry\Impl\RtryPolicyFactory;
use Psr\Retry\Impl\RtryPolicy;
use foreup\rest\models\services\Retry;
use foreup\rest\models\services\NativeSleeper;
use Psr\Clock\ClockInterface;

// Construct runtime pieces (DI in real apps)
$factory = new RtryPolicyFactory();
$policy = $factory->fromSpec('rtry:a=5;d=250ms;mode=exp;b=2;cap=5s;jmode=full;on=5xx,ETIMEDOUT');

$retryer = new Retry($clock /* PSR-20 */, new NativeSleeper());

$response = $retryer->try(function ($ctx) use ($httpClient, $request) {
    $res = $httpClient->sendRequest($request);
    if ($res->getStatusCode() >= 500) {
        throw new RuntimeException('server_error');
    }
    return $res;
}, $policy, ['tenant_id' => 12345, 'correlation_id' => 'req-abc123']);
```

## Interfaces (overview)

- `RetryerInterface` — runs the callable under a policy.
- `RetryPolicyInterface` — attempts, delays, jitter, deadlines, hedging hints, decider.
- `RetryPolicyFactoryInterface` — parses `rtry:` into a policy.
- `AttemptContextInterface` — attempt #, elapsed, remaining budget, context.
- `AttemptOutcomeInterface` — result or error for deciders.
- `JitterSpecInterface` — deterministic jitter application.
- `RetryDeciderInterface` — retry/stop decision.

See **SPEC.md** for normative details and the full RTRY grammar.

## Testing

Provide a test `SleeperInterface` that records sleep calls instead of actually sleeping, and a fixed `ClockInterface` (PSR‑20) for deterministic timing.

## Contributing

1. Open issues/PRs here to refine the draft.
2. If there’s interest, we’ll form a **Working Group** and seek a **Sponsor** inside PHP‑FIG.

## License

- **Code** (interfaces, reference impl): MIT
- **Spec/docs** (SPEC.md, this README): **CC‑BY 4.0**

### Attribution/NOTICE

> © 2025 Gregory Riley and contributors. Licensed under Creative Commons Attribution 4.0 (CC BY 4.0). Changes may have been made. License: https://creativecommons.org/licenses/by/4.0/

## Reference implementation (non-normative)

A production-ready reference implementation lives here:

- Repo: https://github.com/Gohany/Rtry
- Packagist: gohany/rtry (MIT)

This implementation is provided to demonstrate how the interfaces and the `rtry:` policy
can be used in practice. It is **non-normative** and does not change the meaning of this spec.
<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Sanity;

use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Defaults\Http\DefaultMultiHttpCircuitsBuilder;
use Gohany\Circuitbreaker\Defaults\Http\HttpCircuitDefinition;
use Gohany\Circuitbreaker\Defaults\Http\MultiCircuitBreakingPsr18Client;
use Gohany\Circuitbreaker\Exception\CircuitDeniedException;
use Gohany\Circuitbreaker\Integration\Sanity\Output\SanityCheckOutputInterface;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\OutcomeClassifierInterface;
use Gohany\Circuitbreaker\Policy\Fraud\FraudLockoutConfig;
use Gohany\Circuitbreaker\Policy\Fraud\FraudLockoutPolicyDecorator;
use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;
use Gohany\Circuitbreaker\SideEffect\SideEffectDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class SanityCheckRunner
{
    /**
     * @var callable(int):void
     */
    private $sleepMs;

    /**
     * @param callable(int):void|null $sleepMs
     */
    public function __construct(?callable $sleepMs = null)
    {
        $this->sleepMs = $sleepMs ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function runSingle(
        $stateStore,
        $historyStore,
        $probeGate,
        DefaultHttpCircuitPolicy $policy,
        OutcomeClassifierInterface $classifier,
        SideEffectDispatcherInterface $sideEffects,
        SanityCheckOutputInterface $out,
        ?CircuitKey $key = null,
        ?CircuitContext $ctx = null
    ): SanityCheckResult {
        $failures = [];
        $notes = [];

        $key = $key ?? new CircuitKey('sanity:http', ['service' => 'demo']);
        $ctx = $ctx ?? new CircuitContext('t_sanity');

        $out->heading('Circuit breaker sanity check (single-circuit)');
        $out->info('Key=' . $key->name . ' dimensions=' . json_encode($key->dimensions));

        $breaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $policy,
            $classifier,
            [],
            $sideEffects,
            null,
            $probeGate
        );

        $out->step('Trigger two failures (expect circuit to OPEN due to consecutive failures)');
        for ($i = 1; $i <= 2; $i++) {
            try {
                $breaker->execute($key, $ctx, static function () use ($i) {
                    throw new \RuntimeException("simulated timeout #{$i}");
                });
                $failures[] = "expected failure #{$i}, but operation succeeded";
                $out->fail("failure #{$i}: expected exception, got success");
            } catch (\Throwable $e) {
                $out->pass("failure #{$i}: got exception as expected ({$e->getMessage()})");
            }
        }

        $out->step('Attempt operation while OPEN (expect deny)');
        try {
            $breaker->execute($key, $ctx, static fn () => 'ok');
            $failures[] = 'expected deny while OPEN, but operation was allowed';
            $out->fail('allowed while OPEN (unexpected)');
        } catch (CircuitDeniedException $e) {
            $out->pass('denied as expected (OPEN): reason=' . $e->getMessage() . ' retry_after_ms=' . $e->getRetryAfterMs());
        }

        $out->step('Sleep to let open window expire (expect HALF_OPEN probe allowed afterward)');
        $out->info('Sleeping ~5s...');
        ($this->sleepMs)(5000);

        $out->step('Run probe operation (expect success + circuit closes)');
        try {
            $res = $breaker->execute($key, $ctx, static fn () => 'probe_success');
            $out->pass('probe allowed, result=' . (string) $res);
        } catch (\Throwable $e) {
            $failures[] = 'expected probe success, got error: ' . $e->getMessage();
            $out->fail('probe failed: ' . $e->getMessage());
        }

        if ($failures === []) {
            $out->pass('single-circuit sanity check finished OK');
        } else {
            $out->fail('single-circuit sanity check finished with failures=' . count($failures));
        }

        return new SanityCheckResult($failures, $notes);
    }

    public function runMulti(
        $stateStore,
        $historyStore,
        $probeGate,
        DefaultHttpCircuitPolicy $httpPolicy,
        OutcomeClassifierInterface $classifier,
        SideEffectDispatcherInterface $sideEffects,
        SanityCheckOutputInterface $out,
        RequestInterface $req,
        ClientInterface $innerHttp
    ): SanityCheckResult {
        $failures = [];
        $notes = [];

        $out->heading('Circuit breaker sanity check (multi-circuit PSR-18 client)');

        $reliabilityBreaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            $httpPolicy,
            $classifier,
            [],
            $sideEffects,
            null,
            $probeGate
        );

        $fraudClassifier = new class implements OutcomeClassifierInterface {
            public function classify($result, $error, array $context = []): CircuitOutcome
            {
                if ($error instanceof \Throwable) {
                    return new CircuitOutcome(false, ['timeout'], $error, $context, 0);
                }
                if ($result instanceof ResponseInterface && $result->hasHeader('X-Fraud-Suspected')) {
                    return new CircuitOutcome(true, ['fraud_suspected'], null, $context, 0);
                }
                return new CircuitOutcome(true, [], null, $context, 0);
            }
        };

        $fraudBreaker = new CircuitBreaker(
            $stateStore,
            $historyStore,
            new FraudLockoutPolicyDecorator(
                $httpPolicy,
                new FraudLockoutConfig([
                    'lockoutMs' => 10000,
                    'fraudSignals' => ['fraud_suspected'],
                ])
            ),
            $fraudClassifier,
            [],
            $sideEffects,
            null,
            $probeGate
        );

        $client = new MultiCircuitBreakingPsr18Client(
            $innerHttp,
            [
                new HttpCircuitDefinition($reliabilityBreaker, $classifier, 'sanity:payments_http', false),
                new HttpCircuitDefinition($fraudBreaker, $fraudClassifier, 'sanity:payments_fraud', true),
            ],
            new DefaultMultiHttpCircuitsBuilder()
        );

        $out->step('Send 1st request (simulate 500) (expect reliability records a failure)');
        $r1 = $client->sendRequest($req);
        if ($r1->getStatusCode() === 500) {
            $out->pass('got 500 as expected');
        } else {
            $failures[] = 'expected 500 on first request, got ' . $r1->getStatusCode();
            $out->fail('unexpected status=' . $r1->getStatusCode());
        }

        $out->step('Send 2nd request (simulate 200 + fraud signal) (expect fraud lockout opens; may deny after recording)');
        try {
            $r2 = $client->sendRequest($req);
            $out->pass('request allowed; status=' . $r2->getStatusCode() . ' fraud_header=' . ($r2->hasHeader('X-Fraud-Suspected') ? '1' : '0'));
            $notes[] = 'Second request was allowed; depending on policy/config it may instead deny after post-record.';
            $out->info('NOTE: depending on your policies/config, this request may be denied after recording when a circuit opens');
        } catch (CircuitDeniedException $e) {
            $out->pass('denied after recording (expected when fraud opens circuit): reason=' . $e->getMessage() . ' retry_after_ms=' . $e->getRetryAfterMs());
        }

        $out->step('Send 3rd request (expect pre-check deny due to fraud lockout)');
        try {
            $client->sendRequest($req);
            $failures[] = 'expected deny during fraud lockout, but request was allowed';
            $out->fail('UNEXPECTED: request allowed during fraud lockout');
        } catch (CircuitDeniedException $e) {
            $out->pass('denied as expected: reason=' . $e->getMessage() . ' retry_after_ms=' . $e->getRetryAfterMs());
        }

        if ($failures === []) {
            $out->pass('multi-circuit sanity check finished OK');
        } else {
            $out->fail('multi-circuit sanity check finished with failures=' . count($failures));
        }

        return new SanityCheckResult($failures, $notes);
    }
}

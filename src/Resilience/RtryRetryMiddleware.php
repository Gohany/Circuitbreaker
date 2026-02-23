<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

use Gohany\Circuitbreaker\Observability\EmitterInterface;
use Gohany\Circuitbreaker\Observability\NullEmitter;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;

/**
 * Resilience pipeline retry stage backed by gohany/rtry.
 *
 * This is useful when you want retry behaviour defined by a spec string,
 * e.g. `rtry:attempts=3;delay=50ms`.
 */
final class RtryRetryMiddleware implements ResilienceMiddlewareInterface
{
    /** @var string */
    private $spec;

    /** @var EmitterInterface */
    private $emitter;

    public function __construct(string $spec, ?EmitterInterface $emitter = null)
    {
        $this->spec = $spec;
        $this->emitter = $emitter ?: new NullEmitter();
    }

    public function handle(Context $ctx, callable $next)
    {
        $policy = (new RtryPolicyFactory())->fromSpec($this->normalizeSpec($this->spec));

        $retry = new Retry();

        $retry->setBetweenAttemptsHook(function ($attemptContext, $outcome, $policy, int $sleepMs, array $headers = []): void {
            $context = [];
            if (is_object($attemptContext)) {
                if (method_exists($attemptContext, 'context')) {
                    $c = $attemptContext->context();
                    $context = is_array($c) ? $c : [];
                } elseif (method_exists($attemptContext, 'getContext')) {
                    // Backward-compat if older versions used getContext().
                    $c = $attemptContext->getContext();
                    $context = is_array($c) ? $c : [];
                }
            }

            $attempt = is_object($attemptContext) && method_exists($attemptContext, 'attemptNumber')
                ? (int) $attemptContext->attemptNumber()
                : (is_object($attemptContext) && method_exists($attemptContext, 'attempt') ? (int) $attemptContext->attempt() : null);
            $maxAttempts = is_object($attemptContext) && method_exists($attemptContext, 'maxAttempts')
                ? (int) $attemptContext->maxAttempts()
                : null;

            $lastError = null;
            if (is_object($outcome) && method_exists($outcome, 'error')) {
                $lastError = $outcome->error();
            }

            $this->emitter->emit('retry.attempt', [
                'operation' => $context['operation'] ?? null,
                'lane' => $context['lane'] ?? null,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'error_class' => $lastError instanceof \Throwable ? get_class($lastError) : null,
                'sleep_ms' => $sleepMs,
            ]);
        });

        $retry->setOnGiveUpHook(function ($attemptContext, $outcome, $policy, array $headers = []): void {
            $context = [];
            if (is_object($attemptContext)) {
                if (method_exists($attemptContext, 'context')) {
                    $c = $attemptContext->context();
                    $context = is_array($c) ? $c : [];
                } elseif (method_exists($attemptContext, 'getContext')) {
                    $c = $attemptContext->getContext();
                    $context = is_array($c) ? $c : [];
                }
            }

            $attempt = is_object($attemptContext) && method_exists($attemptContext, 'attemptNumber')
                ? (int) $attemptContext->attemptNumber()
                : (is_object($attemptContext) && method_exists($attemptContext, 'attempt') ? (int) $attemptContext->attempt() : null);
            $maxAttempts = is_object($attemptContext) && method_exists($attemptContext, 'maxAttempts')
                ? (int) $attemptContext->maxAttempts()
                : null;

            $error = null;
            if (is_object($outcome) && method_exists($outcome, 'error')) {
                $error = $outcome->error();
            }

            $this->emitter->emit('retry.give_up', [
                'operation' => $context['operation'] ?? null,
                'lane' => $context['lane'] ?? null,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'error_class' => $error instanceof \Throwable ? get_class($error) : null,
            ]);
        });

        $outcome = $retry->try(
            static function () use ($ctx, $next) {
                return $next($ctx);
            },
            $policy,
            [
                'operation' => $ctx->getOperation(),
                'lane' => $ctx->getLane(),
            ]
        );

        if (is_object($outcome)) {
            if (method_exists($outcome, 'getResult')) {
                return $outcome->getResult();
            }
            if (method_exists($outcome, 'result')) {
                return $outcome->result();
            }
        }

        // Fallback: return as-is (should not happen for rtry outcomes, but keeps this middleware defensive).
        return $outcome;
    }

    private function normalizeSpec(string $spec): string
    {
        $s = trim($spec);

        // Keep optional rtry: prefix.
        $hasPrefix = stripos($s, 'rtry:') === 0;
        if ($hasPrefix) {
            $s = substr($s, 5);
        }

        if ($s === '') {
            return $spec;
        }

        $aliases = [
            // Human-friendly aliases (used in docs/bundles) -> rtry short keys.
            'attempts' => 'a',
            'max_attempts' => 'a',
            'delay' => 'd',
        ];

        $pairs = array_map('trim', explode(';', $s));
        $out = [];
        foreach ($pairs as $pair) {
            if ($pair === '') {
                continue;
            }
            $pos = strpos($pair, '=');
            if ($pos === false) {
                $out[] = $pair;
                continue;
            }
            $k = strtolower(trim(substr($pair, 0, $pos)));
            $v = trim(substr($pair, $pos + 1));
            if (isset($aliases[$k])) {
                $k = $aliases[$k];
            }
            $out[] = $k . '=' . $v;
        }

        return ($hasPrefix ? 'rtry:' : '') . implode(';', $out);
    }
}

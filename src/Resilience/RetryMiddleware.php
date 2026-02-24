<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

use Gohany\Circuitbreaker\Observability\EmitterInterface;
use Gohany\Circuitbreaker\Observability\NullEmitter;

/**
 * @deprecated Use {@see RtryRetryMiddleware}.
 *
 * This middleware is retained for backward compatibility, but new code should use
 * `RtryRetryMiddleware` directly so retry behaviour is driven by a `rtry:` spec string.
 */
final class RetryMiddleware implements ResilienceMiddlewareInterface
{
    /** @var RtryRetryMiddleware */
    private $inner;

    /**
     * @param RetryConfig|string $cfgOrSpec
     */
    public function __construct($cfgOrSpec, ?EmitterInterface $emitter = null)
    {
        $emitter = $emitter ?: new NullEmitter();

        if ($cfgOrSpec instanceof RetryConfig) {
            // Best-effort mapping from the legacy config to a rtry spec.
            // Note: legacy `retryOn` exception-class filtering is NOT representable as a rtry spec.
            // If you relied on that behaviour, migrate to an explicit rtry decider in your wiring.
            $attempts = max(1, (int) $cfgOrSpec->maxAttempts);
            $delayMs = max(0, (int) $cfgOrSpec->baseDelayMs);
            $spec = 'rtry:a=' . $attempts . ';d=' . $delayMs . 'ms;on=default';
        } else {
            $spec = (string) $cfgOrSpec;
        }

        $this->inner = new RtryRetryMiddleware($spec, $emitter);
    }

    public function handle(Context $ctx, callable $next)
    {
        return $this->inner->handle($ctx, $next);
    }
}

<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

use Gohany\Circuitbreaker\Observability\EmitterInterface;
use Gohany\Circuitbreaker\Observability\NullEmitter;

final class RetryMiddleware implements ResilienceMiddlewareInterface
{
    /** @var RetryConfig */
    private $cfg;
    /** @var EmitterInterface */
    private $emitter;

    public function __construct(RetryConfig $cfg, ?EmitterInterface $emitter = null)
    {
        $this->cfg = $cfg;
        $this->emitter = $emitter ?: new NullEmitter();
    }

    public function handle(Context $ctx, callable $next)
    {
        $attempt = 0;
        $last = null;

        while (true) {
            $attempt++;
            try {
                $this->emitter->emit('retry.attempt', [
                    'operation' => $ctx->getOperation(),
                    'lane' => $ctx->getLane(),
                    'attempt' => $attempt,
                    'max_attempts' => $this->cfg->maxAttempts,
                ]);
                return $next($ctx);
            } catch (\Throwable $e) {
                $last = $e;
                if ($attempt >= $this->cfg->maxAttempts || !$this->shouldRetry($e)) {
                    $this->emitter->emit('retry.give_up', [
                        'operation' => $ctx->getOperation(),
                        'lane' => $ctx->getLane(),
                        'attempt' => $attempt,
                        'error_class' => get_class($e),
                    ]);
                    throw $e;
                }

                $delayMs = $this->computeDelayMs($attempt);
                $this->emitter->emit('retry.sleep', [
                    'operation' => $ctx->getOperation(),
                    'lane' => $ctx->getLane(),
                    'attempt' => $attempt,
                    'delay_ms' => $delayMs,
                    'error_class' => get_class($e),
                ]);
                usleep($delayMs * 1000);
            }
        }
    }

    private function shouldRetry(\Throwable $e): bool
    {
        foreach ($this->cfg->retryOn as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }
        return false;
    }

    private function computeDelayMs(int $attempt): int
    {
        $exp = (int) ($this->cfg->baseDelayMs * (2 ** max(0, $attempt - 2)));
        $delay = min($this->cfg->maxDelayMs, $exp);
        if ($this->cfg->jitter) {
            $delay = (int) random_int((int) floor($delay * 0.5), (int) ceil($delay * 1.5));
            $delay = max(0, min($this->cfg->maxDelayMs, $delay));
        }
        return $delay;
    }
}

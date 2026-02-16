<?php declare(strict_types=1);

namespace Gohany\Retry;

interface AttemptContextInterface
{
    public function attemptNumber(): int;

    public function maxAttempts(): int;

    /**
     * Milliseconds waited before this attempt (including jitter), or 0 for the first attempt
     * if no start-after delay was applied.
     */
    public function scheduledDelayMs(): int;

    /** Milliseconds since the very first attempt started. */
    public function elapsedSinceFirstMs(): int;

    /** Remaining overall deadline budget in milliseconds (clamped at 0), or null if unlimited. */
    public function remainingBudgetMs(): ?int;

    /** @return array<string,mixed> */
    public function context(): array;
}

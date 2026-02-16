<?php declare(strict_types=1);

namespace Gohany\Retry;

interface RetryPolicyInterface
{

    public function attempts(): int;

    public function attemptTimeoutMs(): ?int;

    public function deadlineBudgetMs(): ?int;

    public function startAfterMs(): int;

    public function delayMs(): ?int;

    public function followHeaders(): bool;

    public function capMs(): int;

    public function nextDelayMs(int $attemptNumber): int;

    public function sequence(): ?SequenceInterface;

    public function hedge(): ?HedgeInterface;

    public function jitter(): ?JitterInterface;

    public function decider(): RetryDeciderInterface;

}
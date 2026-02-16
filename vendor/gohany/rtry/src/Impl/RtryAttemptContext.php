<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Rtry\Contracts\RtryAttemptContextInterface;

final class RtryAttemptContext implements RtryAttemptContextInterface
{
    private int $attemptNumber;

    private int $maxAttempts;

    private int $scheduledDelayMs;

    private int $elapsedSinceFirstMs;

    private ?int $remainingBudgetMs;

    /** @var array<string,mixed> */
    private array $context = [];

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        int $attemptNumber,
        int $maxAttempts,
        int $scheduledDelayMs,
        int $elapsedSinceFirstMs,
        ?int $remainingBudgetMs,
        array $context = []
    ) {
        $this->attemptNumber = $attemptNumber;
        $this->maxAttempts = $maxAttempts;
        $this->scheduledDelayMs = $scheduledDelayMs;
        $this->elapsedSinceFirstMs = $elapsedSinceFirstMs;
        $this->remainingBudgetMs = $remainingBudgetMs;
        $this->context = $context;
    }

    public function attemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function scheduledDelayMs(): int
    {
        return $this->scheduledDelayMs;
    }

    public function elapsedSinceFirstMs(): int
    {
        return $this->elapsedSinceFirstMs;
    }

    public function remainingBudgetMs(): ?int
    {
        return $this->remainingBudgetMs;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function setRemainingBudgetMs(?int $remainingBudgetMs): RtryAttemptContextInterface
    {
        $this->remainingBudgetMs = $remainingBudgetMs;
        return $this;
    }
}
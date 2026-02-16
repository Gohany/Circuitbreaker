<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Rtry;

use Gohany\Retry\AttemptContextInterface;

final class AttemptCounterHook
{
    private int $attempts = 1;

    public function __invoke(AttemptContextInterface $context): void
    {
        $this->attempts = $context->attemptNumber() + ($context->outcome() === null ? 1 : 0);
    }

    public function recordBetween(AttemptContextInterface $context): void
    {
        $this->attempts = $context->attemptNumber() + 1;
    }

    public function recordGiveUp(AttemptContextInterface $context): void
    {
        $this->attempts = $context->attemptNumber();
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }
}

<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

use Gohany\Retry\AttemptContextInterface;

interface RtryAttemptContextInterface extends AttemptContextInterface
{
    public function setRemainingBudgetMs(?int $remainingBudgetMs): self;
}
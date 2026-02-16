<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Deciders;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryDeciderInterface;

final class CompositeDecider implements RetryDeciderInterface
{
    /** @var RetryDeciderInterface[] */
    private array $deciders;

    public function __construct(RetryDeciderInterface ...$deciders)
    {
        $this->deciders = $deciders;
    }

    public function shouldRetry(AttemptOutcomeInterface $outcome, AttemptContextInterface $context): bool
    {
        foreach ($this->deciders as $d) {
            if ($d->shouldRetry($outcome, $context)) {
                return true;
            }
        }
        return false;
    }
}
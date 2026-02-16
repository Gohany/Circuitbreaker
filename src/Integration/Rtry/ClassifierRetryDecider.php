<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Rtry;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Policy\OutcomeClassifierInterface;
use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryDeciderInterface;

final class ClassifierRetryDecider implements RetryDeciderInterface
{
    private OutcomeClassifierInterface $classifier;
    private CircuitKey $key;
    private CircuitContext $context;

    public function __construct(OutcomeClassifierInterface $classifier, CircuitKey $key, CircuitContext $context)
    {
        $this->classifier = $classifier;
        $this->key = $key;
        $this->context = $context;
    }

    public function shouldRetry(AttemptOutcomeInterface $outcome, AttemptContextInterface $context): bool
    {
        if ($outcome->isSuccess()) {
            return false;
        }

        $circuitOutcome = $this->classifier->classify(null, $outcome->error(), [
            'circuit' => $this->key->name,
            'dimensions' => $this->key->dimensions,
            'retry_attempt' => $context->attemptNumber(),
        ]);

        return in_array('transient_failure', $circuitOutcome->signals, true);
    }
}

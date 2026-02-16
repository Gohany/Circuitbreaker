<?php

declare(strict_types=1);

namespace tests;

use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Integration\Rtry\RetrySpec;
use Gohany\Circuitbreaker\Integration\Rtry\RetrySpecProviderInterface;
use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\OutcomeClassifierInterface;
use Gohany\Circuitbreaker\Policy\PolicyDecision;
use Gohany\Circuitbreaker\Policy\TransitionPlan;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use tests\TestDoubles\ProbePolicyStub;

final class TestOutcomeClassifier implements OutcomeClassifierInterface
{
    public array $capturedContexts = [];

    public function classify($result, $error, array $context = []): CircuitOutcome
    {
        $this->capturedContexts[] = $context;
        $signals = [];
        if ($error instanceof \RuntimeException && $error->getMessage() === 'transient') {
            $signals[] = 'transient_failure';
        }
        return new CircuitOutcome($error === null, $signals, $error, [], 0);
    }
}

final class TestRetrySpecProviderPolicy extends ProbePolicyStub implements RetrySpecProviderInterface
{
    private RetrySpec $spec;

    public function __construct(RetrySpec $spec)
    {
        parent::__construct(
            new PolicyDecision(true, 'ok', null, [], false),
            new ProbeGateConfig(1, true),
            new TransitionPlan(null, null, [], [])
        );
        $this->spec = $spec;
    }

    public function getRetrySpec(CircuitKey $key, CircuitContext $context): ?RetrySpec
    {
        return $this->spec;
    }
}

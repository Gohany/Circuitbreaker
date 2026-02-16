<?php

declare(strict_types=1);

namespace tests\TestDoubles;

use Gohany\Circuitbreaker\Policy\CircuitOutcome;
use Gohany\Circuitbreaker\Policy\OutcomeClassifierInterface;

final class ClassifierStub implements OutcomeClassifierInterface
{
    private bool $success;

    public function __construct(bool $success = true)
    {
        $this->success = $success;
    }

    public function classify($result, $error, array $context = []): CircuitOutcome
    {
        return new CircuitOutcome($this->success, [], $error, [], 0);
    }
}

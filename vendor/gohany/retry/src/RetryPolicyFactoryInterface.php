<?php declare(strict_types=1);

namespace Gohany\Retry;

interface RetryPolicyFactoryInterface
{
    public function fromSpec(string $spec, ?RetryDeciderInterface $decider = null): RetryPolicyInterface;
}

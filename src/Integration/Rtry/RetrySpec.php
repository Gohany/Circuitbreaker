<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Rtry;

use Gohany\Rtry\Contracts\RtryPolicyInterface;

final class RetrySpec
{
    private RtryPolicyInterface $policy;
    /** @var array<string,mixed> */
    private array $metadata;

    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(RtryPolicyInterface $policy, array $metadata = [])
    {
        $this->policy = $policy;
        $this->metadata = $metadata;
    }

    public function getPolicy(): RtryPolicyInterface
    {
        return $this->policy;
    }

    /**
     * @return array<string,mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

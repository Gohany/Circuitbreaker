<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Sanity;

final class SanityCheckResult
{
    /** @var list<string> */
    public array $failures;

    /** @var list<string> */
    public array $notes;

    /**
     * @param list<string> $failures
     * @param list<string> $notes
     */
    public function __construct(array $failures = [], array $notes = [])
    {
        $this->failures = $failures;
        $this->notes = $notes;
    }

    public function isOk(): bool
    {
        return $this->failures === [];
    }
}

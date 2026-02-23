<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Contracts;

interface BulkheadPermitInterface
{
    /**
     * Release capacity back to the bulkhead.
     */
    public function release(): void;

    /**
     * A stable identifier for observability and debugging.
     */
    public function getId(): string;

    /**
     * Lane name used for accounting.
     */
    public function getLane(): string;
}

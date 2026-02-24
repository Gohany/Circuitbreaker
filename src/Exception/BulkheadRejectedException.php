<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Exception;

use RuntimeException;

final class BulkheadRejectedException extends RuntimeException
{
    /** @var string */
    private $bulkheadId;
    /** @var string */
    private $lane;

    public function __construct(string $bulkheadId, string $lane, string $message = 'Bulkhead rejected')
    {
        parent::__construct($message);
        $this->bulkheadId = $bulkheadId;
        $this->lane = $lane;
    }

    public function getBulkheadId(): string
    {
        return $this->bulkheadId;
    }

    public function getLane(): string
    {
        return $this->lane;
    }
}

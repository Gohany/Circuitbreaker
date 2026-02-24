<?php

declare(strict_types=1);

namespace tests\Exception;

use Gohany\Circuitbreaker\Exception\BulkheadRejectedException;
use PHPUnit\Framework\TestCase;

final class BulkheadRejectedExceptionTest extends TestCase
{
    public function testCarriesBulkheadIdAndLane(): void
    {
        $e = new BulkheadRejectedException('bh', 'lane');

        $this->assertSame('Bulkhead rejected', $e->getMessage());
        $this->assertSame('bh', $e->getBulkheadId());
        $this->assertSame('lane', $e->getLane());
    }
}

<?php

declare(strict_types=1);

namespace tests\Bulkhead;

use Gohany\Circuitbreaker\Bulkhead\SemaphoreBulkhead;
use Gohany\Circuitbreaker\Exception\BulkheadRejectedException;
use PHPUnit\Framework\TestCase;

final class SemaphoreBulkheadTest extends TestCase
{
    public function testAcquireRejectsAtMaxAndReleaseAllowsAnotherAcquire(): void
    {
        $bh = new SemaphoreBulkhead('bh', 1);

        $p1 = $bh->acquire('default');

        try {
            $this->expectException(BulkheadRejectedException::class);
            $bh->acquire('default');
        } finally {
            $p1->release();
        }

        // After release, acquire should succeed again.
        $p2 = $bh->acquire('default');
        $p2->release();
        $this->assertTrue(true);
    }

    public function testRunAlwaysReleasesOnException(): void
    {
        $bh = new SemaphoreBulkhead('bh', 1);

        try {
            $bh->run('default', function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // If permit leaked, this would reject.
        $p = $bh->acquire('default');
        $p->release();
        $this->assertTrue(true);
    }
}

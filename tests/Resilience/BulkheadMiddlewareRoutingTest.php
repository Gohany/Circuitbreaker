<?php

declare(strict_types=1);

namespace tests\Resilience;

use Gohany\Circuitbreaker\Contracts\BulkheadInterface;
use Gohany\Circuitbreaker\Contracts\BulkheadPermitInterface;
use Gohany\Circuitbreaker\Resilience\BulkheadMiddleware;
use Gohany\Circuitbreaker\Resilience\Context;
use Gohany\Circuitbreaker\Resilience\MapLaneRouter;
use PHPUnit\Framework\TestCase;

final class BulkheadMiddlewareRoutingTest extends TestCase
{
    public function testUsesRouterSelectedLaneWhenProvided(): void
    {
        $seen = [];

        $bulkhead = new class($seen) implements BulkheadInterface {
            /** @var array<int,string> */
            private array $seen;

            /** @param array<int,string> $seen */
            public function __construct(array &$seen)
            {
                $this->seen = &$seen;
            }

            public function acquire(string $lane, ?float $timeoutSeconds = null): BulkheadPermitInterface
            {
                $this->seen[] = $lane;
                return new class($lane) implements BulkheadPermitInterface {
                    private string $lane;
                    public function __construct(string $lane) { $this->lane = $lane; }
                    public function release(): void {}
                    public function getId(): string { return 'p1'; }
                    public function getLane(): string { return $this->lane; }
                };
            }

            public function run(string $lane, callable $fn, ?float $timeoutSeconds = null)
            {
                $p = $this->acquire($lane, $timeoutSeconds);
                try {
                    return $fn();
                } finally {
                    $p->release();
                }
            }
        };

        $router = new MapLaneRouter('route', [
            'payments_charge' => 'payments',
            'auth_login' => 'login',
        ], [], [], 'default');

        $mw = new BulkheadMiddleware($bulkhead, null, $router);

        $ctx = new Context('op', 'default');
        $ctx->set('route', 'payments_charge');

        $res = $mw->handle($ctx, static function (Context $ctx) {
            return $ctx->getLane();
        });

        $this->assertSame('payments', $res);
        $this->assertSame(['payments'], $seen);
    }

    public function testDefaultsToContextLaneWhenNoRouterProvided(): void
    {
        $seen = [];

        $bulkhead = new class($seen) implements BulkheadInterface {
            /** @var array<int,string> */
            private array $seen;

            /** @param array<int,string> $seen */
            public function __construct(array &$seen)
            {
                $this->seen = &$seen;
            }

            public function acquire(string $lane, ?float $timeoutSeconds = null): BulkheadPermitInterface
            {
                $this->seen[] = $lane;
                return new class($lane) implements BulkheadPermitInterface {
                    private string $lane;
                    public function __construct(string $lane) { $this->lane = $lane; }
                    public function release(): void {}
                    public function getId(): string { return 'p2'; }
                    public function getLane(): string { return $this->lane; }
                };
            }

            public function run(string $lane, callable $fn, ?float $timeoutSeconds = null)
            {
                $p = $this->acquire($lane, $timeoutSeconds);
                try {
                    return $fn();
                } finally {
                    $p->release();
                }
            }
        };

        $mw = new BulkheadMiddleware($bulkhead);
        $ctx = new Context('op', 'login');

        $mw->handle($ctx, static function () {
            return 'ok';
        });

        $this->assertSame(['login'], $seen);
    }
}

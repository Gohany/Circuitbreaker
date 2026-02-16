<?php

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;

final class RedisKeyBuilderPropertyTest extends TestCase
{
    public function testKeyStabilityAcrossRandomDimensionOrderings(): void
    {
        $kb = new RedisKeyBuilder('cb', false);

        $baseDims = [
            'tenant' => 22542,
            'subject' => 'card:fp:abc',
            'service' => 'finix',
            'endpoint' => '/v1/charges',
            'flag' => true,
            'optional' => null,
            'nested' => ['a' => 1, 'b' => 2],
        ];

        $expected = null;

        for ($i = 0; $i < 200; $i++) {
            $dims = $this->shuffleAssoc($baseDims);
            $key = new CircuitKey('svc', $dims);

            $stateKey = $kb->stateKey($key);
            $countersKey = $kb->countersKey($key);
            $bucketPattern = $kb->bucketPattern($key);
            $overrideKey = $kb->overrideKey($key);

            if ($expected === null) {
                $expected = [
                    'state' => $stateKey,
                    'counters' => $countersKey,
                    'bucketPattern' => $bucketPattern,
                    'override' => $overrideKey,
                ];
                continue;
            }

            $this->assertSame($expected['state'], $stateKey);
            $this->assertSame($expected['counters'], $countersKey);
            $this->assertSame($expected['bucketPattern'], $bucketPattern);
            $this->assertSame($expected['override'], $overrideKey);
        }
    }

    public function testHumanReadableModeStillStableAcrossOrdering(): void
    {
        $kb = new RedisKeyBuilder('cb', true);

        $dimsA = ['b' => '2', 'a' => '1', 'c' => '3'];
        $dimsB = ['c' => '3', 'b' => '2', 'a' => '1'];

        $k1 = new CircuitKey('svc', $dimsA);
        $k2 = new CircuitKey('svc', $dimsB);

        $this->assertSame($kb->stateKey($k1), $kb->stateKey($k2));
        $this->assertSame($kb->bucketPattern($k1), $kb->bucketPattern($k2));
        $this->assertSame($kb->overrideKey($k1), $kb->overrideKey($k2));
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function shuffleAssoc(array $a): array
    {
        $keys = array_keys($a);
        shuffle($keys);

        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $a[$k];
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace tests\Redis;

use Gohany\Circuitbreaker\Redis\RedisLuaCapabilitiesDetector;
use Gohany\Circuitbreaker\Redis\RedisLuaCapability;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;

final class RedisLuaCapabilitiesDetectorTest extends TestCase
{
    /** @return Redis&MockObject */
    private function mockRedis(array $onlyMethods): Redis
    {
        return $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods($onlyMethods)
            ->getMock();
    }

    public function testReturnsCapabilityFromSharedRedisCache(): void
    {
        $redis = $this->mockRedis(['get']);

        $redis
            ->expects(self::once())
            ->method('get')
            ->with('cap:key:1')
            ->willReturn(RedisLuaCapability::EVAL_ONLY);

        $det = new RedisLuaCapabilitiesDetector($redis, 'cap:key:1', 60, 60, true);

        self::assertSame(RedisLuaCapability::EVAL_ONLY, $det->getCapability());
    }

    public function testProbePrefersScriptLoadWhenAvailable(): void
    {
        $redis = $this->mockRedis(['get', 'set', 'script', 'evalSha']);

        $redis
            ->method('get')
            ->willReturn(null);

        $redis
            ->expects(self::once())
            ->method('script')
            ->with('load', 'return 1')
            ->willReturn('abc123');

        $redis
            ->expects(self::once())
            ->method('evalSha')
            ->with('abc123', [], 0)
            ->willReturn(1);

        $redis
            ->expects(self::once())
            ->method('set')
            ->with('cap:key:2', RedisLuaCapability::SCRIPT_CACHEABLE, self::isType('array'))
            ->willReturn(true);

        $det = new RedisLuaCapabilitiesDetector($redis, 'cap:key:2', 60, 60, true);
        self::assertSame(RedisLuaCapability::SCRIPT_CACHEABLE, $det->getCapability());
    }

    public function testProbeFallsBackToEvalOnlyWhenScriptLoadFails(): void
    {
        $redis = $this->mockRedis(['get', 'script', 'eval']);

        $redis->method('get')->willReturn(null);

        $redis
            ->method('script')
            ->willThrowException(new RedisException('ERR unknown command'));

        $redis
            ->expects(self::once())
            ->method('eval')
            ->with('return 1', [], 0)
            ->willReturn(1);

        $det = new RedisLuaCapabilitiesDetector($redis, 'cap:key:3', 60, 60, false);
        self::assertSame(RedisLuaCapability::EVAL_ONLY, $det->getCapability());
    }

    public function testProbeReturnsNoLuaWhenAllProbesFail(): void
    {
        $redis = $this->mockRedis(['get', 'script', 'eval']);

        $redis->method('get')->willReturn(null);
        $redis->method('script')->willThrowException(new RedisException('ERR'));
        $redis->method('eval')->willThrowException(new RedisException('ERR'));

        $det = new RedisLuaCapabilitiesDetector($redis, 'cap:key:4', 60, 60, false);
        self::assertSame(RedisLuaCapability::NO_LUA, $det->getCapability());
    }
}

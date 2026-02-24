<?php

declare(strict_types=1);

namespace tests\Redis;

use Gohany\Circuitbreaker\Redis\ExtRedisClient;
use Gohany\Circuitbreaker\Redis\RedisLuaCapability;
use Gohany\Circuitbreaker\Redis\RedisLuaNotAvailableException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;

final class ExtRedisClientTest extends TestCase
{
    /** @return Redis&MockObject */
    private function mockRedis(array $onlyMethods): Redis
    {
        return $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods($onlyMethods)
            ->getMock();
    }

    public function testRunLuaScriptCacheableLoadsAndUsesEvalSha(): void
    {
        $redis = $this->mockRedis(['get', 'script', 'evalSha']);

        $redis->method('get')->with('cap:key:script')->willReturn(RedisLuaCapability::SCRIPT_CACHEABLE);

        $redis
            ->expects(self::once())
            ->method('script')
            ->with('load', 'return ARGV[1]')
            ->willReturn('sha1');

        $redis
            ->expects(self::once())
            ->method('evalSha')
            ->with('sha1', ['k1', 'a1'], 1)
            ->willReturn('ok');

        $client = new ExtRedisClient($redis, 'cap:key:script', 60, 60, true);

        self::assertSame('ok', $client->runLua('s1', 'return ARGV[1]', ['k1'], ['a1']));
    }

    public function testRunLuaReloadsOnNoScript(): void
    {
        $redis = $this->mockRedis(['get', 'script', 'evalSha']);
        $redis->method('get')->with('cap:key:noscript')->willReturn(RedisLuaCapability::SCRIPT_CACHEABLE);

        $redis
            ->expects(self::exactly(2))
            ->method('script')
            ->with('load', 'return 1')
            ->willReturnOnConsecutiveCalls('sha1', 'sha2');

        $redis
            ->expects(self::exactly(2))
            ->method('evalSha')
            ->withConsecutive(
                ['sha1', [], 0],
                ['sha2', [], 0]
            )
            ->willReturnOnConsecutiveCalls(
                self::throwException(new RedisException('NOSCRIPT No matching script')),
                1
            );

        $client = new ExtRedisClient($redis, 'cap:key:noscript', 60, 60, true);
        self::assertSame(1, $client->runLua('s1', 'return 1', [], []));
    }

    public function testRunLuaEvalOnlyUsesEval(): void
    {
        $redis = $this->mockRedis(['get', 'eval']);
        $redis->method('get')->with('cap:key:eval')->willReturn(RedisLuaCapability::EVAL_ONLY);

        $redis
            ->expects(self::once())
            ->method('eval')
            ->with('return KEYS[1]', ['k1'], 1)
            ->willReturn('k1');

        $client = new ExtRedisClient($redis, 'cap:key:eval', 60, 60, true);
        self::assertSame('k1', $client->runLua('s1', 'return KEYS[1]', ['k1'], []));
    }

    public function testRunLuaThrowsWhenNoLua(): void
    {
        $redis = $this->mockRedis(['get']);
        $redis->method('get')->with('cap:key:nolua')->willReturn(RedisLuaCapability::NO_LUA);

        $client = new ExtRedisClient($redis, 'cap:key:nolua', 60, 60, true);

        $this->expectException(RedisLuaNotAvailableException::class);
        $client->runLua('s1', 'return 1', [], []);
    }
}

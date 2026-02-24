<?php
declare(strict_types=1);

namespace Gohany\Circuitbreaker\Redis;

/**
 * Redis Lua capability levels for this client/endpoint.
 *
 * - SCRIPT_CACHEABLE: SCRIPT LOAD + EVALSHA works (preferred).
 * - EVAL_ONLY: EVAL works, but SCRIPT commands are not allowed.
 * - NO_LUA: Lua is not available (EVAL blocked).
 */
final class RedisLuaCapability
{
    public const SCRIPT_CACHEABLE = 'script_cacheable';
    public const EVAL_ONLY        = 'eval_only';
    public const NO_LUA           = 'no_lua';

    private function __construct() {}
}

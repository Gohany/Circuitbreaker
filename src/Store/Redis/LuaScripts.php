<?php

namespace Gohany\Circuitbreaker\Store\Redis;

final class LuaScripts
{
    private function __construct() {}

    public const CAS_UPDATE_STATE = <<<'LUA'
local key = KEYS[1]
local expected = tostring(ARGV[1])
local curv = redis.call('HGET', key, 'version')
if curv == false then curv = '0' end
if tostring(curv) ~= expected then
  return 0
end

local newv = tonumber(curv) + 1
redis.call('HSET', key,
  'version', newv,
  'mode', ARGV[2],
  'open_until_ms', ARGV[3],
  'half_open_in_flight', ARGV[4],
  'meta_json', ARGV[5]
)

local pexp = tonumber(ARGV[6])
if pexp ~= nil and pexp > 0 then
  redis.call('PEXPIRE', key, pexp)
end

return 1
LUA;

    public const ACQUIRE_PROBE = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local maxInFlight = tonumber(ARGV[2])
local allowOpenExpiredToHalfOpen = tonumber(ARGV[4])
local pexp = tonumber(ARGV[5])

local mode = redis.call('HGET', key, 'mode')
if mode == false then
  mode = 'closed'
end

local stateOpenUntil = redis.call('HGET', key, 'open_until_ms')
if stateOpenUntil == false then
  stateOpenUntil = ''
end

local inFlight = redis.call('HGET', key, 'half_open_in_flight')
if inFlight == false then
  inFlight = '0'
end
inFlight = tonumber(inFlight)

if mode == 'open' then
  local ou = stateOpenUntil
  if ou ~= '' then ou = tonumber(ou) end

  if allowOpenExpiredToHalfOpen == 1 and ou ~= nil and now >= ou then
    mode = 'half_open'
    stateOpenUntil = ''
    redis.call('HSET', key, 'mode', mode, 'open_until_ms', '')
  else
    local retryAfter = 0
    if ou ~= nil and now < ou then retryAfter = ou - now end
    return {0, mode, tostring(stateOpenUntil), inFlight, retryAfter}
  end
end

if mode == 'half_open' then
  if inFlight >= maxInFlight then
    return {0, mode, tostring(stateOpenUntil), inFlight, 250}
  end
  inFlight = inFlight + 1
  redis.call('HSET', key, 'half_open_in_flight', inFlight)
  if pexp ~= nil and pexp > 0 then redis.call('PEXPIRE', key, pexp) end
  return {1, mode, tostring(stateOpenUntil), inFlight, 0}
end

return {1, mode, tostring(stateOpenUntil), inFlight, 0}
LUA;

    public const RELEASE_PROBE = <<<'LUA'
local key = KEYS[1]
local mode = redis.call('HGET', key, 'mode')
if mode ~= 'half_open' then
  return tonumber(redis.call('HGET', key, 'half_open_in_flight') or '0')
end

local inFlight = redis.call('HGET', key, 'half_open_in_flight')
if inFlight == false then inFlight = '0' end
inFlight = tonumber(inFlight)
if inFlight > 0 then
  inFlight = inFlight - 1
  redis.call('HSET', key, 'half_open_in_flight', inFlight)
end
return inFlight
LUA;

    public const RECORD_HISTORY = <<<'LUA'
local counters = KEYS[1]
local bucket = KEYS[2]

local now = tonumber(ARGV[1])
local success = tonumber(ARGV[2])
local duration = tonumber(ARGV[3])
local signalsCsv = tostring(ARGV[4])

local bucketTtl = tonumber(ARGV[5])
local countersTtl = tonumber(ARGV[6])

if success == 1 then
  redis.call('HINCRBY', counters, 'total_success', 1)
  redis.call('HSET', counters, 'last_success_ms', now)
  redis.call('HSET', counters, 'consecutive_failures', 0)
else
  redis.call('HINCRBY', counters, 'total_failure', 1)
  redis.call('HSET', counters, 'last_failure_ms', now)
  redis.call('HINCRBY', counters, 'consecutive_failures', 1)
end

redis.call('HINCRBY', counters, 'total_duration_ms', duration)

if success == 1 then
  redis.call('HINCRBY', bucket, 'success', 1)
else
  redis.call('HINCRBY', bucket, 'failure', 1)
end

if signalsCsv ~= '' then
  for sig in string.gmatch(signalsCsv, '([^,]+)') do
    redis.call('HINCRBY', bucket, sig, 1)
  end
end

if bucketTtl ~= nil and bucketTtl > 0 then
  redis.call('EXPIRE', bucket, bucketTtl)
end
if countersTtl ~= nil and countersTtl > 0 then
  redis.call('EXPIRE', counters, countersTtl)
end

local cf = tonumber(redis.call('HGET', counters, 'consecutive_failures') or '0')
local ts = tonumber(redis.call('HGET', counters, 'total_success') or '0')
local tf = tonumber(redis.call('HGET', counters, 'total_failure') or '0')
return {cf, ts, tf}
LUA;
}

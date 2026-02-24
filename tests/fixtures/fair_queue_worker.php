<?php
declare(strict_types=1);

/**
 * Real-Redis worker used by integration tests for RedisFairQueueBulkhead.
 *
 * Outputs one line JSON:
 *   {"lane":"payments.charge","ok":12,"rejected":3,"errors":0}
 */
use Redis;

/**
 * @return array<string,int>
 */
function parseLaneMap(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '{}' || $raw === '[]') {
        return [];
    }

    // JSON object/array
    if (strpos($raw, '{') === 0 || strpos($raw, '[') === 0) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $k => $v) {
                $k = (string) $k;
                if ($k === '') {
                    continue;
                }
                $out[$k] = (int) $v;
            }
            return $out;
        }
        return [];
    }

    // CSV k=v,k2=v2
    $out = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $eq = strpos($part, '=');
        if ($eq === false) {
            continue;
        }
        $k = trim(substr($part, 0, $eq));
        $v = trim(substr($part, $eq + 1));
        if ($k === '') {
            continue;
        }
        $out[$k] = (int) $v;
    }
    return $out;
}

function arg(string $name, $default = null) {
    global $argv;
    foreach ($argv as $v) {
        if (strpos($v, '--' . $name . '=') === 0) {
            return substr($v, strlen('--' . $name . '='));
        }
    }
    return $default;
}

$dsn = (string) arg('dsn', getenv('GOHANY_CB_TEST_REDIS_DSN') ?: '');
$prefix = (string) arg('prefix', getenv('GOHANY_CB_TEST_REDIS_PREFIX') ?: 'it');
$poolId = (string) arg('poolId', 'it');
$lane = (string) arg('lane', 'default');
$globalMax = (int) arg('globalMax', 10);
$mode = (string) arg('mode', 'fixed');
$iterations = (int) arg('iterations', 10);
$holdMs = (int) arg('holdMs', 25);
$timeoutMs = (int) arg('timeoutMs', 250);
$scanLimit = (int) arg('scanLimit', 64);
$pumpPerCall = (int) arg('pumpPerCall', 3);
$grantTtlMs = (int) arg('grantTtlMs', 250);
$pollIntervalMs = (int) arg('pollIntervalMs', 10);

$crashAfterGrant = (int) arg('crashAfterGrant', 0);

$laneWeightsJson = (string) arg('laneWeights', '{}');
$laneCapsJson = (string) arg('laneCaps', '{}');

$barrierKey = (string) arg('barrierKey', '');
$readyKey = (string) arg('readyKey', '');
$barrierTimeoutMs = (int) arg('barrierTimeoutMs', 5000);

if ($dsn === '') {
    fwrite(STDERR, "Missing --dsn or GOHANY_CB_TEST_REDIS_DSN\n");
    exit(2);
}

$parts = parse_url($dsn);
if (!is_array($parts) || !isset($parts['host'])) {
    fwrite(STDERR, "Invalid redis DSN\n");
    exit(2);
}
$host = $parts['host'];
$port = (int)($parts['port'] ?? 6379);
$db = 0;
if (isset($parts['path']) && $parts['path'] !== '') {
    $db = (int) trim($parts['path'], '/');
}

$redis = new Redis();
if (!$redis->connect($host, $port, 1.5)) {
    fwrite(STDERR, "Failed to connect Redis\n");
    exit(2);
}
if (isset($parts['pass']) && $parts['pass'] !== '') {
    $redis->auth($parts['pass']);
}
$redis->select($db);

if ($readyKey !== '') {
    $redis->set($readyKey, '1', 10);
}
if ($barrierKey !== '') {
    // Wait until barrier released
    $deadline = microtime(true) + max(0.1, ((float) $barrierTimeoutMs) / 1000.0);
    while (microtime(true) < $deadline) {
        if ($redis->exists($barrierKey)) {
            break;
        }
        usleep(5000);
    }

    if (!$redis->exists($barrierKey)) {
        fwrite(STDERR, "Barrier was not released in time: {$barrierKey}\n");
        exit(3);
    }
}

$ok = 0;
$rejected = 0;
$errors = 0;

// NOTE: This fixture assumes the library exposes classes used in previous packs.
// We keep this file patch-oriented; projects will have these in repo.
require_once __DIR__ . '/../../vendor/autoload.php';

try {
    $laneWeights = parseLaneMap($laneWeightsJson);
    $laneCaps = parseLaneMap($laneCapsJson);

    $client = new \Gohany\Circuitbreaker\Util\ExtRedisClient($redis);

    /** @var array<string,\Gohany\Circuitbreaker\Bulkhead\LanePolicy> $lanes */
    $lanes = [];
    if ($mode === \Gohany\Circuitbreaker\Bulkhead\PoolPolicy::MODE_WEIGHTED) {
        if (!is_array($laneWeights) || $laneWeights === []) {
            $laneWeights = [$lane => 1];
        }
        if (!array_key_exists($lane, $laneWeights)) {
            $laneWeights[$lane] = 1;
        }
        foreach ($laneWeights as $ln => $w) {
            $ln = (string) $ln;
            if ($ln === '') {
                continue;
            }
            $lanes[$ln] = \Gohany\Circuitbreaker\Bulkhead\LanePolicy::weight($ln, max(1, (int) $w));
        }
    } else {
        if (!is_array($laneCaps) || $laneCaps === []) {
            $laneCaps = [$lane => $globalMax];
        }
        if (!array_key_exists($lane, $laneCaps)) {
            $laneCaps[$lane] = $globalMax;
        }
        foreach ($laneCaps as $ln => $cap) {
            $ln = (string) $ln;
            if ($ln === '') {
                continue;
            }
            $lanes[$ln] = \Gohany\Circuitbreaker\Bulkhead\LanePolicy::fixed($ln, max(1, (int) $cap));
        }
        $mode = \Gohany\Circuitbreaker\Bulkhead\PoolPolicy::MODE_FIXED;
    }

    $poolPolicy = new \Gohany\Circuitbreaker\Bulkhead\PoolPolicy(
        $poolId,
        $globalMax,
        $mode,
        0.0,
        $lanes
    );

    // Simulate a process crash after a grant is created but before it is finalized/consumed.
    // This exercises grant TTL recovery behaviour: the leaked grant key should expire and not permanently block progress.
    if ($crashAfterGrant === 1) {
        $reqId = bin2hex(random_bytes(8));
        $nowMs = (int) (microtime(true) * 1000);
        $deadlineMs = $nowMs + max(1, $timeoutMs);
        $member = $reqId . '|' . $lane . '|' . (string) $deadlineMs;

        $queueKey = $prefix . ':bulkhead:pool:' . $poolId . ':queue';
        $grantKey = $prefix . ':bulkhead:pool:' . $poolId . ':grant:' . $reqId;

        // Enqueue, then grant, then exit without consuming (no counter increments).
        $redis->zAdd($queueKey, (float) $nowMs, $member);
        $redis->zRem($queueKey, $member);
        $redis->set($grantKey, $lane, ['nx' => true, 'px' => $grantTtlMs]);

        echo json_encode([
            'lane' => $lane,
            'ok' => 0,
            'rejected' => 0,
            'errors' => 0,
        ]) . PHP_EOL;
        exit(0);
    }

    $bulkhead = new \Gohany\Circuitbreaker\Bulkhead\RedisFairQueueBulkhead(
        $client,
        $poolPolicy,
        $prefix,
        null,
        [
            'scan_limit' => $scanLimit,
            'pump_per_call' => $pumpPerCall,
            'grant_ttl_ms' => $grantTtlMs,
            'poll_interval_ms' => $pollIntervalMs,
        ]
    );

    for ($i = 0; $i < $iterations; $i++) {
        try {
            $lease = $bulkhead->acquire($lane, $timeoutMs / 1000.0);
            // Track in-flight concurrency in Redis for assertions.
            $cur = (int) $redis->incr($prefix . ':current_in_flight');
            // Update max observed
            while (true) {
                $max = (int) ($redis->get($prefix . ':max_in_flight') ?: 0);
                if ($cur <= $max) {
                    break;
                }
                if ($redis->set($prefix . ':max_in_flight', (string)$cur, ['xx' => true]) || $max === 0) {
                    $redis->set($prefix . ':max_in_flight', (string)$cur);
                    break;
                }
            }

            usleep($holdMs * 1000);

            $redis->decr($prefix . ':current_in_flight');
            $lease->release();
            $ok++;
        } catch (\Gohany\Circuitbreaker\Exception\BulkheadRejectedException $e) {
            $rejected++;
            usleep(1000);
        } catch (\Throwable $e) {
            $errors++;
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
        }
    }
} catch (\Throwable $e) {
    $errors++;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
}

echo json_encode([
    'lane' => $lane,
    'ok' => $ok,
    'rejected' => $rejected,
    'errors' => $errors,
]) . PHP_EOL;

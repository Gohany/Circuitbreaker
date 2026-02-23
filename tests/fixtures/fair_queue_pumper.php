<?php
declare(strict_types=1);

/**
 * Pump-only (or pump-forcing) worker.
 *
 * Prefers calling a public pump method on RedisFairQueueBulkhead if present:
 *   - pump()
 *   - pumpOnce()
 *   - pumpQueue()
 *
 * Otherwise it falls back to repeatedly calling acquire() with timeout=0 and immediately
 * releasing if acquired, which forces the bulkhead to execute its pump path under contention.
 *
 * Outputs JSON: {"lane":"pumper","ok":0,"rejected":0,"errors":0,"pumps":1234}
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
$lane = (string) arg('lane', 'pumper');
$globalMax = (int) arg('globalMax', 3);
$mode = (string) arg('mode', 'weighted');
$durationMs = (int) arg('durationMs', 1000);
$scanLimit = (int) arg('scanLimit', 64);
$pumpPerCall = (int) arg('pumpPerCall', 3);
$grantTtlMs = (int) arg('grantTtlMs', 500);
$pollIntervalMs = (int) arg('pollIntervalMs', 2);

$laneWeightsJson = (string) arg('laneWeights', '{}');
$laneCapsJson = (string) arg('laneCaps', '{}');

if ($dsn === '') { fwrite(STDERR, "Missing DSN\n"); exit(2); }

$parts = parse_url($dsn);
if (!is_array($parts) || !isset($parts['host'])) { fwrite(STDERR, "Invalid DSN\n"); exit(2); }
$host = $parts['host'];
$port = (int)($parts['port'] ?? 6379);
$db = 0;
if (isset($parts['path']) && $parts['path'] !== '') { $db = (int) trim($parts['path'], '/'); }

$redis = new Redis();
if (!$redis->connect($host, $port, 1.5)) { fwrite(STDERR, "Redis connect fail\n"); exit(2); }
if (isset($parts['pass']) && $parts['pass'] !== '') { $redis->auth($parts['pass']); }
$redis->select($db);

require_once __DIR__ . '/../../vendor/autoload.php';

$ok = 0;
$rejected = 0;
$errors = 0;
$pumps = 0;

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
        'it',
        $globalMax,
        $mode,
        0.0,
        $lanes
    );

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

    $deadline = microtime(true) + ($durationMs / 1000.0);

    $pumpMethod = null;
    foreach (['pump', 'pumpOnce', 'pumpQueue'] as $m) {
        if (method_exists($bulkhead, $m)) { $pumpMethod = $m; break; }
    }

    while (microtime(true) < $deadline) {
        try {
            if ($pumpMethod !== null) {
                // Most direct: just pump.
                $bulkhead->{$pumpMethod}();
                $pumps++;
            } else {
                // Fallback: force the pump path by doing a 0-timeout acquire/release.
                $lease = $bulkhead->acquire($lane, 0);
                $cur = (int) $redis->incr($prefix . ':current_in_flight');
                // best-effort max update
                $max = (int)($redis->get($prefix . ':max_in_flight') ?: 0);
                if ($cur > $max) { $redis->set($prefix . ':max_in_flight', (string)$cur); }
                $redis->decr($prefix . ':current_in_flight');
                $lease->release();
                $ok++;
            }
        } catch (\Gohany\Circuitbreaker\Exception\BulkheadRejectedException $e) {
            $rejected++;
        } catch (\Throwable $e) {
            $errors++;
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
        }
        usleep(1000); // 1ms tight loop, keeps pressure without pegging CPU too hard
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
    'pumps' => $pumps,
]) . PHP_EOL;

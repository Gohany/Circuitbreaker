<?php

declare(strict_types=1);

use Gohany\Circuitbreaker\Bulkhead\LanePolicy;
use Gohany\Circuitbreaker\Bulkhead\PoolPolicy;
use Gohany\Circuitbreaker\Bulkhead\RedisFairQueueBulkhead;
use Gohany\Circuitbreaker\Exception\BulkheadRejectedException;
use Gohany\Circuitbreaker\Util\ExtRedisClient;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Sanity check for RedisFairQueueBulkhead.
 *
 * This intentionally runs as a short, human-friendly check:
 *  - verifies global_max is enforced
 *  - verifies higher-weight lane is admitted more often under contention
 *
 * Usage:
 *   php bin/sanity/fair_queue.php --dsn redis://127.0.0.1:6379/15 --prefix sanity
 */

$opts = getopt('', ['dsn:', 'prefix:', 'global-max:', 'iters:', 'lane:', 'worker']);
$dsn = (string)($opts['dsn'] ?? 'redis://127.0.0.1:6379/15');
$prefix = (string)($opts['prefix'] ?? 'sanity');
$globalMax = (int)($opts['global-max'] ?? 4);
$iters = (int)($opts['iters'] ?? 200);

if (!class_exists(Redis::class)) {
    fwrite(STDERR, "ext-redis is required (class Redis not found)\n");
    exit(2);
}

if (!class_exists(RedisFairQueueBulkhead::class)) {
    fwrite(STDERR, "RedisFairQueueBulkhead not found; did you apply the fair-queue patches?\n");
    exit(3);
}

$parts = parse_url($dsn);
$host = (string)($parts['host'] ?? '127.0.0.1');
$port = isset($parts['port']) ? (int)$parts['port'] : 6379;
$db = 0;
if (!empty($parts['path'])) {
    $db = (int)ltrim((string)$parts['path'], '/');
}

$r = new Redis();
if (!$r->connect($host, $port, 1.5)) {
    fwrite(STDERR, "Cannot connect to Redis (start it with docker compose -f docker-compose.redis.yml up -d)\n");
    exit(4);
}
if ($db > 0) {
    $r->select($db);
}

// Clean up prefix.
$it = null;
while (true) {
    $keys = $r->scan($it, $prefix . ':*', 500);
    if ($keys === false) {
        break;
    }
    if (!empty($keys)) {
        // phpcs:ignore
        $r->del($keys);
    }
    if ($it === 0) {
        break;
    }
}

$redis = new ExtRedisClient($r);

$policy = new PoolPolicy(
    'sanity-pool',
    $globalMax,
    PoolPolicy::MODE_WEIGHTED,
    0.60,
    [
        'auth.login' => LanePolicy::weight('auth.login', 1),
        'payments.charge' => LanePolicy::weight('payments.charge', 6),
    ]
);

$bh = new RedisFairQueueBulkhead($redis, $policy, $prefix, null, [
    'scan_limit' => 64,
    'pump_per_call' => 3,
    'grant_ttl_ms' => 250,
    'poll_interval_ms' => 10,
]);

$keyMax = $prefix . ':sanity_max';

// Bulkhead's authoritative in-flight counter key.
$globalKey = $prefix . ':bulkhead:pool:sanity-pool:global';

$maxLua = <<<'LUA'
-- KEYS[1] maxKey
-- ARGV[1] cur
local cur = tonumber(ARGV[1])
local mx = tonumber(redis.call('GET', KEYS[1]) or '0')
if cur > mx then
  redis.call('SET', KEYS[1], cur)
  return cur
end
return mx
LUA;

function runLane(RedisFairQueueBulkhead $bh, Redis $r, string $lane, int $iters, float $timeout, int $holdMs, string $globalKey, string $keyMax, string $maxLua): array
{
    $ok = 0;
    $rej = 0;
    for ($i = 0; $i < $iters; $i++) {
        try {
            $permit = $bh->acquire($lane, $timeout);
        } catch (BulkheadRejectedException $e) {
            $rej++;
            continue;
        }

        $cur = (int) ($r->get($globalKey) ?: 0);
        // ext-redis signature: eval(string $script, array $args = [], int $num_keys = 0)
        $r->eval($maxLua, [$keyMax, (string) $cur], 1);
        usleep($holdMs * 1000);
        $permit->release();
        $ok++;
    }
    return ['lane' => $lane, 'ok' => $ok, 'rejected' => $rej];
}

/**
 * @param list<string> $cmd
 * @return array{proc: resource, pipes: array<int, resource>}
 */
function startProcess(array $cmd): array
{
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($cmd, $spec, $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start process: ' . implode(' ', $cmd));
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], true);
    stream_set_blocking($pipes[2], true);

    return ['proc' => $proc, 'pipes' => $pipes];
}

/**
 * @return array{stdout:string,stderr:string,exitCode:int}
 */
function waitProcess(array $handle): array
{
    $stdout = stream_get_contents($handle['pipes'][1]) ?: '';
    $stderr = stream_get_contents($handle['pipes'][2]) ?: '';
    fclose($handle['pipes'][1]);
    fclose($handle['pipes'][2]);

    $exitCode = proc_close($handle['proc']);

    return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => (int) $exitCode];
}

// Worker mode: run a single lane and output JSON so the parent can create contention.
if (isset($opts['worker'])) {
    $lane = (string)($opts['lane'] ?? '');
    if ($lane === '') {
        fwrite(STDERR, "Missing --lane in --worker mode\n");
        exit(2);
    }

    $res = runLane($bh, $r, $lane, $iters, 0.05, 20, $globalKey, $keyMax, $maxLua);
    echo json_encode($res) . "\n";
    exit(0);
}

// Create real contention by running many contenders across both lanes.
$workersPerLane = 6;
$perWorkerIters = max(1, (int) floor($iters / ($workersPerLane * 2)));

$cmdBase = [
    PHP_BINARY,
    __FILE__,
    '--dsn=' . $dsn,
    '--prefix=' . $prefix,
    '--global-max=' . (string) $globalMax,
    '--iters=' . (string) $perWorkerIters,
    '--worker',
];

$login = ['lane' => 'auth.login', 'ok' => 0, 'rejected' => 0];
$pay = ['lane' => 'payments.charge', 'ok' => 0, 'rejected' => 0];

$procs = [];
for ($i = 0; $i < $workersPerLane; $i++) {
    $procs[] = ['lane' => 'auth.login', 'h' => startProcess(array_merge($cmdBase, ['--lane=auth.login']))];
    $procs[] = ['lane' => 'payments.charge', 'h' => startProcess(array_merge($cmdBase, ['--lane=payments.charge']))];
}

foreach ($procs as $p) {
    $out = waitProcess($p['h']);
    if ($out['exitCode'] !== 0) {
        fwrite(STDERR, "FAIL: worker process failed\n");
        fwrite(STDERR, "lane={$p['lane']} exit={$out['exitCode']} stderr={$out['stderr']}\n");
        exit(12);
    }

    $res = json_decode(trim($out['stdout']), true);
    if (!is_array($res)) {
        fwrite(STDERR, "FAIL: invalid worker JSON output\n");
        fwrite(STDERR, "lane={$p['lane']} raw: {$out['stdout']}\n");
        exit(13);
    }

    if ($p['lane'] === 'auth.login') {
        $login['ok'] += (int) ($res['ok'] ?? 0);
        $login['rejected'] += (int) ($res['rejected'] ?? 0);
    } else {
        $pay['ok'] += (int) ($res['ok'] ?? 0);
        $pay['rejected'] += (int) ($res['rejected'] ?? 0);
    }
}

$maxObserved = (int)($r->get($keyMax) ?: 0);

echo "Global max: {$globalMax}\n";
echo "Max observed: {$maxObserved}\n";
echo "Login ok={$login['ok']} rejected={$login['rejected']}\n";
echo "Pay   ok={$pay['ok']} rejected={$pay['rejected']}\n";

if ($maxObserved > $globalMax) {
    fwrite(STDERR, "FAIL: observed concurrency exceeds global_max\n");
    exit(10);
}

// Only enforce strict preference when the weighted caps are meaningfully different.
$capLogin = max(1, (int) floor($globalMax * (1 / 7)));
$capPay = max(1, (int) floor($globalMax * (6 / 7)));
if ($capPay > $capLogin) {
    if ($pay['ok'] <= $login['ok']) {
        fwrite(STDERR, "FAIL: expected payments to be admitted more often than login under contention\n");
        exit(11);
    }
} else {
    if ($pay['ok'] < 0 || $login['ok'] < 0) {
        fwrite(STDERR, "FAIL: invalid results\n");
        exit(11);
    }
}

echo "OK\n";

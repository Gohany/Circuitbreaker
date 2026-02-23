<?php

declare(strict_types=1);

// Extended sanity checks for fair queue bulkhead.

$dsn = getenv('GOHANY_CB_TEST_REDIS_DSN') ?: 'redis://127.0.0.1:6379/15';
$prefix = getenv('GOHANY_CB_TEST_REDIS_PREFIX') ?: 'sanity';

$worker = __DIR__ . '/../../tests/fixtures/fair_queue_worker.php';
if (!is_file($worker)) {
    fwrite(STDERR, "Worker fixture not found: {$worker}\n");
    exit(2);
}

$cmd = [
    PHP_BINARY,
    $worker,
    '--dsn=' . $dsn,
    '--prefix=' . $prefix,
    '--poolId=sanity-ext',
    '--lane=payments.charge',
    '--globalMax=3',
    '--mode=weighted',
    '--laneWeights=auth.login=1,payments.charge=6',
    '--iterations=20',
    '--holdMs=10',
    '--timeoutMs=500',
    '--scanLimit=64',
    '--pumpPerCall=3',
    '--grantTtlMs=200',
    '--pollIntervalMs=5',
];

$spec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$proc = proc_open($cmd, $spec, $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "Failed to start worker process\n");
    exit(3);
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]) ?: '';
$stderr = stream_get_contents($pipes[2]) ?: '';
fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($proc);
if ($stderr !== '') {
    // Keep stderr visible to help diagnose Redis/env issues.
    fwrite(STDERR, $stderr);
}
if ($exitCode !== 0) {
    exit($exitCode);
}

$data = json_decode(trim($stdout), true);
if (!is_array($data)) {
    fwrite(STDERR, "Worker did not output JSON. Raw: {$stdout}\n");
    exit(4);
}

$lane = (string)($data['lane'] ?? '');
$ok = (int)($data['ok'] ?? 0);
$errors = (int)($data['errors'] ?? 0);

if ($lane !== 'payments.charge') {
    fwrite(STDERR, "Expected lane=payments.charge, got lane={$lane}\n");
    exit(5);
}
if ($errors !== 0) {
    fwrite(STDERR, "Worker reported errors={$errors}\n");
    exit(6);
}
if ($ok <= 0) {
    fwrite(STDERR, "Expected ok>0, got ok={$ok}\n");
    exit(7);
}

fwrite(STDOUT, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");
exit(0);

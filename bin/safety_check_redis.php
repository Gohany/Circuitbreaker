<?php

declare(strict_types=1);

$dsn = getenv('GOHANY_CB_TEST_REDIS_DSN') ?: 'redis://127.0.0.1:6379/15';

$parts = parse_url($dsn);
$host = $parts['host'] ?? '127.0.0.1';
$port = (int) ($parts['port'] ?? 6379);

$fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
if (!$fp) {
    fwrite(STDERR, "Redis not reachable at $host:$port\n");
    exit(2);
}
fclose($fp);
exit(0);

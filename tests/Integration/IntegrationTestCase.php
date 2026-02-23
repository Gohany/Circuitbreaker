<?php
declare(strict_types=1);

namespace tests\Integration;

use PHPUnit\Framework\TestCase;
use Redis;

/**
 * Base class for integration tests that require a real Redis.
 *
 * - Uses GOHANY_CB_TEST_REDIS_DSN (e.g. redis://127.0.0.1:6379/15)
 * - Uses GOHANY_CB_TEST_REDIS_PREFIX (default "it")
 *
 * Keys created by tests MUST be namespaced under $this->prefix(<suffix>).
 */
abstract class IntegrationTestCase extends TestCase
{
    private ?Redis $redis = null;
    private string $basePrefix = 'it';

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = (string) getenv('GOHANY_CB_TEST_REDIS_DSN');
        if ($dsn === '') {
            $this->markTestSkipped('GOHANY_CB_TEST_REDIS_DSN not set.');
        }

        $this->basePrefix = (string) (getenv('GOHANY_CB_TEST_REDIS_PREFIX') ?: 'it');

        $this->redis = $this->connectRedisFromDsn($dsn);
        if (!$this->redis instanceof Redis) {
            $this->markTestSkipped('Redis not reachable.');
        }

        // Clean base prefix space to reduce cross-test interference.
        $this->purgePrefix($this->basePrefix . ':');
    }

    protected function tearDown(): void
    {
        if ($this->redis instanceof Redis) {
            // Best-effort cleanup.
            $this->purgePrefix($this->basePrefix . ':');
            $this->redis->close();
        }

        $this->redis = null;
        parent::tearDown();
    }

    protected function redis(): Redis
    {
        if (!$this->redis instanceof Redis) {
            throw new \RuntimeException('Redis not initialized.');
        }
        return $this->redis;
    }

    protected function prefix(string $suffix): string
    {
        return $this->basePrefix . ':' . $suffix;
    }

    protected function waitForKey(string $key, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($this->redis()->exists($key)) {
                return;
            }
            usleep(10000); // 10ms
        }
        $this->fail('Timed out waiting for Redis key: ' . $key);
    }

    /**
     * Start a PHP process and return an array handle.
     *
     * @param list<string> $cmd
     * @return array{proc: resource, pipes: array<int, resource>, cmd: list<string>}
     */
    protected function startProcess(array $cmd): array
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $spec, $pipes);
        if (!is_resource($proc)) {
            $this->fail('Failed to start process: ' . implode(' ', $cmd));
        }

        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);

        return ['proc' => $proc, 'pipes' => $pipes, 'cmd' => $cmd];
    }

    protected function stopProcess(?array $handle): void
    {
        if (!$handle) {
            return;
        }
        $proc = $handle['proc'] ?? null;
        $pipes = $handle['pipes'] ?? [];
        if (is_resource($pipes[0] ?? null)) {
            fclose($pipes[0]);
        }
        if (is_resource($pipes[1] ?? null)) {
            fclose($pipes[1]);
        }
        if (is_resource($pipes[2] ?? null)) {
            fclose($pipes[2]);
        }
        if (is_resource($proc)) {
            @proc_terminate($proc);
            @proc_close($proc);
        }
    }

    protected function waitProcess(array $handle, float $timeoutSeconds): string
    {
        $proc = $handle['proc'];
        $pipes = $handle['pipes'];

        $deadline = microtime(true) + $timeoutSeconds;
        $stdout = '';
        $stderr = '';

        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                if ($stderr !== '') {
                    // keep stderr available for debugging failures
                    // but don't fail automatically; callers decide.
                }
                return $stdout;
            }
            usleep(20000);
        }

        $this->stopProcess($handle);
        $this->fail('Timed out waiting for process: ' . implode(' ', $handle['cmd']));
    }

    /**
     * Spawn the standard fair-queue worker fixture used by RedisFairQueueBulkhead integration tests.
     *
     * @param array<string,mixed> $cfg
     * @return array{proc: resource, pipes: array<int, resource>, cmd: list<string>}
     */
    protected function spawnWorker(array $cfg): array
    {
        $worker = __DIR__ . '/../fixtures/fair_queue_worker.php';
        $this->assertFileExists($worker);

        $dsn = (string) ($cfg['dsn'] ?? getenv('GOHANY_CB_TEST_REDIS_DSN'));
        if ($dsn === '') {
            $this->markTestSkipped('GOHANY_CB_TEST_REDIS_DSN not set.');
        }

        $args = [
            PHP_BINARY,
            $worker,
            '--dsn=' . $dsn,
            '--prefix=' . (string) ($cfg['prefix'] ?? $this->prefix('it')),
            '--poolId=' . (string) ($cfg['poolId'] ?? 'it'),
            '--lane=' . (string) ($cfg['lane'] ?? 'default'),
            '--globalMax=' . (string) (int) ($cfg['globalMax'] ?? 1),
            '--mode=' . (string) ($cfg['mode'] ?? 'fixed'),
            '--iterations=' . (string) (int) ($cfg['iterations'] ?? 1),
            '--holdMs=' . (string) (int) ($cfg['holdMs'] ?? 0),
            '--timeoutMs=' . (string) (int) ($cfg['timeoutMs'] ?? 0),
            '--scanLimit=' . (string) (int) ($cfg['queueScanLimit'] ?? $cfg['scanLimit'] ?? 64),
            '--pumpPerCall=' . (string) (int) ($cfg['pumpPerCall'] ?? 3),
            '--grantTtlMs=' . (string) (int) ($cfg['grantTtlMs'] ?? 250),
            '--pollIntervalMs=' . (string) (int) ($cfg['pollIntervalMs'] ?? 10),
        ];

        if (isset($cfg['laneWeights'])) {
            $args[] = '--laneWeights=' . (string) $cfg['laneWeights'];
        }
        if (isset($cfg['laneCaps'])) {
            $args[] = '--laneCaps=' . (string) $cfg['laneCaps'];
        }
        if (isset($cfg['crashAfterGrant'])) {
            $args[] = '--crashAfterGrant=' . (string) (int) $cfg['crashAfterGrant'];
        }

        return $this->startProcess($args);
    }

    /**
     * Wait for a worker spawned by spawnWorker() and decode its JSON output.
     *
     * @return array<string,mixed>
     */
    protected function waitForWorker(array $handle, float $timeoutSeconds = 12.0): array
    {
        $out = $this->waitProcess($handle, $timeoutSeconds);
        $data = json_decode(trim($out), true);
        $this->assertIsArray($data, 'Worker output must be JSON. Raw: ' . $out);
        /** @var array<string,mixed> $data */
        return $data;
    }

    private function purgePrefix(string $prefix): void
    {
        $redis = $this->redis();
        $it = null;
        do {
            $keys = $redis->scan($it, $prefix . '*', 1000);
            if ($keys !== false && $keys !== []) {
                $redis->del($keys);
            }
        } while ($it !== 0 && $it !== null);
    }

    private function connectRedisFromDsn(string $dsn): ?Redis
    {
        // Minimal DSN parsing for redis://host:port/db
        $parts = parse_url($dsn);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }
        $host = $parts['host'];
        $port = (int)($parts['port'] ?? 6379);
        $db = 0;
        if (isset($parts['path']) && $parts['path'] !== '') {
            $db = (int) trim($parts['path'], '/');
        }

        $redis = new Redis();
        if (!$redis->connect($host, $port, 1.5)) {
            return null;
        }
        if (isset($parts['pass']) && $parts['pass'] !== '') {
            if (!$redis->auth($parts['pass'])) {
                return null;
            }
        }
        $redis->select($db);

        return $redis;
    }
}

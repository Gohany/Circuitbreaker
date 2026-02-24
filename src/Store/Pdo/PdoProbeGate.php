<?php

namespace Gohany\Circuitbreaker\Store\Pdo;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\ProbeGateConfig;
use Gohany\Circuitbreaker\Store\ProbeGateInterface;
use Gohany\Circuitbreaker\Store\ProbeGateResult;
use PDO;

final class PdoProbeGate implements ProbeGateInterface
{
    private PDO $pdo;
    private string $tableName;

    public function __construct(PDO $pdo, string $tableName = 'circuit_probe_gates')
    {
        $this->pdo = $pdo;
        $this->tableName = $tableName;
    }

    public function acquire(CircuitKey $key, ProbeGateConfig $config, int $nowMs): ProbeGateResult
    {
        // Table-based in-flight counter.
        // Schema expectation: (circuit_key PRIMARY KEY, in_flight INT NOT NULL)

        $max = max(1, (int) $config->maxInFlight);

        // Attempt insert (fast-path for first acquire).
        $stmt = $this->pdo->prepare("INSERT INTO {$this->tableName} (circuit_key, in_flight) VALUES (:key, 1)");
        try {
            if ($stmt->execute(['key' => $key->id()])) {
                return new ProbeGateResult(true, 'half_open', 1, 0);
            }
        } catch (\PDOException $e) {
            if ($e->getCode() != '23000') {
                throw $e;
            }
            // Duplicate key -> fall through to CAS-style increment.
        }

        // CAS-style increment only when below max.
        $stmt = $this->pdo->prepare("
            UPDATE {$this->tableName}
            SET in_flight = in_flight + 1
            WHERE circuit_key = :key AND in_flight < :max
        ");
        $stmt->execute([
            'key' => $key->id(),
            'max' => $max,
        ]);

        if ($stmt->rowCount() > 0) {
            $cur = (int) $this->pdo
                ->query("SELECT in_flight FROM {$this->tableName} WHERE circuit_key = " . $this->pdo->quote($key->id()))
                ->fetchColumn();
            return new ProbeGateResult(true, 'half_open', $cur, 0);
        }

        // Denied.
        $cur = 0;
        try {
            $cur = (int) $this->pdo
                ->query("SELECT in_flight FROM {$this->tableName} WHERE circuit_key = " . $this->pdo->quote($key->id()))
                ->fetchColumn();
        } catch (\Throwable $e) {
            // best-effort; keep cur=0
        }
        return new ProbeGateResult(false, 'half_open', $cur, 250);
    }

    public function release(CircuitKey $key): void
    {
        // Best-effort decrement.
        $stmt = $this->pdo->prepare("
            UPDATE {$this->tableName}
            SET in_flight = CASE WHEN in_flight > 0 THEN in_flight - 1 ELSE 0 END
            WHERE circuit_key = :key
        ");
        $stmt->execute(['key' => $key->id()]);

        // If it reached 0, delete the row to keep table small.
        $stmt = $this->pdo->prepare("DELETE FROM {$this->tableName} WHERE circuit_key = :key AND in_flight <= 0");
        $stmt->execute(['key' => $key->id()]);
    }
}

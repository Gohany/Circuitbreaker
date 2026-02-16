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
        // We use a simple table-based lock with an expiration.
        // If the row doesn't exist, we try to insert it.
        // If it exists, we check if it's expired.
        
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tableName} WHERE circuit_key = :key");
        $stmt->execute(['key' => $key->id()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $expiresAt = $nowMs + $config->timeoutMs;
            $stmt = $this->pdo->prepare("
                INSERT INTO {$this->tableName} (circuit_key, expires_at_ms)
                VALUES (:key, :expires)
            ");
            try {
                if ($stmt->execute(['key' => $key->id(), 'expires' => $expiresAt])) {
                    return ProbeGateResult::granted();
                }
            } catch (\PDOException $e) {
                if ($e->getCode() == '23000') {
                    // Someone else just acquired it
                    return ProbeGateResult::denied();
                }
                throw $e;
            }
        }

        $currentExpiresAt = (int) $row['expires_at_ms'];
        if ($nowMs > $currentExpiresAt) {
            // Expired, try to re-acquire (atomic update)
            $newExpiresAt = $nowMs + $config->timeoutMs;
            $stmt = $this->pdo->prepare("
                UPDATE {$this->tableName}
                SET expires_at_ms = :new_expires
                WHERE circuit_key = :key AND expires_at_ms = :old_expires
            ");
            $stmt->execute([
                'new_expires' => $newExpiresAt,
                'key' => $key->id(),
                'old_expires' => $currentExpiresAt
            ]);

            if ($stmt->rowCount() > 0) {
                return ProbeGateResult::granted();
            }
        }

        return ProbeGateResult::denied();
    }

    public function release(CircuitKey $key): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->tableName} WHERE circuit_key = :key");
        $stmt->execute(['key' => $key->id()]);
    }
}

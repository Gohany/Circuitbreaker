<?php

namespace Gohany\Circuitbreaker\Store\Pdo;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\CircuitStateStoreInterface;
use Gohany\Circuitbreaker\Consts\CircuitStateMode;
use PDO;

final class PdoCircuitStateStore implements CircuitStateStoreInterface
{
    private PDO $pdo;
    private string $tableName;

    public function __construct(PDO $pdo, string $tableName = 'circuit_states')
    {
        $this->pdo = $pdo;
        $this->tableName = $tableName;
    }

    public function getState(CircuitKey $key): CircuitState
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tableName} WHERE circuit_key = :key");
        $stmt->execute(['key' => $key->id()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return new CircuitState(CircuitStateMode::CLOSED, null, 0, ['version' => 0]);
        }

        $meta = [];
        if (!empty($row['meta_json'])) {
            $meta = json_decode($row['meta_json'], true) ?: [];
        }
        $meta['version'] = (int) $row['version'];

        return new CircuitState(
            (string) $row['mode'],
            $row['open_until_ms'] !== null ? (int) $row['open_until_ms'] : null,
            (int) $row['half_open_in_flight'],
            $meta
        );
    }

    public function casUpdateState(CircuitKey $key, CircuitState $expected, CircuitState $new): bool
    {
        $expectedVersion = isset($expected->meta['version']) ? (int) $expected->meta['version'] : 0;
        $newVersion = $expectedVersion + 1;
        
        $newMeta = $new->meta;
        $newMeta['version'] = $newVersion;
        unset($newMeta['version']); // We store version in its own column for CAS
        
        $metaJson = json_encode($newMeta);

        if ($expectedVersion === 0) {
            // Attempt to insert if it doesn't exist
            $stmt = $this->pdo->prepare("
                INSERT INTO {$this->tableName} (circuit_key, mode, open_until_ms, half_open_in_flight, meta_json, version)
                VALUES (:key, :mode, :until, :inflight, :meta, :version)
            ");
            
            try {
                return $stmt->execute([
                    'key' => $key->id(),
                    'mode' => $new->mode,
                    'until' => $new->openUntilMs,
                    'inflight' => $new->halfOpenInFlight,
                    'meta' => $metaJson,
                    'version' => $newVersion
                ]);
            } catch (\PDOException $e) {
                // Duplicate key means someone else inserted it, or we should have done an update
                // Check if it's a unique constraint violation
                if ($e->getCode() == '23000') {
                    return false;
                }
                throw $e;
            }
        }

        $stmt = $this->pdo->prepare("
            UPDATE {$this->tableName}
            SET mode = :mode,
                open_until_ms = :until,
                half_open_in_flight = :inflight,
                meta_json = :meta,
                version = :new_version
            WHERE circuit_key = :key AND version = :expected_version
        ");

        $stmt->execute([
            'mode' => $new->mode,
            'until' => $new->openUntilMs,
            'inflight' => $new->halfOpenInFlight,
            'meta' => $metaJson,
            'new_version' => $newVersion,
            'key' => $key->id(),
            'expected_version' => $expectedVersion
        ]);

        return $stmt->rowCount() > 0;
    }
}

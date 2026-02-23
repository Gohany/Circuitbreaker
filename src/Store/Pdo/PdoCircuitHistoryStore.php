<?php

namespace Gohany\Circuitbreaker\Store\Pdo;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\CircuitHistory;
use Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface;
use Gohany\Circuitbreaker\Store\HistoryRecord;
use PDO;

final class PdoCircuitHistoryStore implements CircuitHistoryStoreInterface
{
    private PDO $pdo;
    private string $tableName;
    private int $retentionLimit;

    public function __construct(PDO $pdo, string $tableName = 'circuit_history', int $retentionLimit = 100)
    {
        $this->pdo = $pdo;
        $this->tableName = $tableName;
        $this->retentionLimit = $retentionLimit;
    }

    public function getHistory(CircuitKey $key): CircuitHistory
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->tableName} 
            WHERE circuit_key = :key
            ORDER BY ts_ms DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':key', $key->id());
        $stmt->bindValue(':limit', $this->retentionLimit, PDO::PARAM_INT);
        $stmt->execute();

        $window = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $signals = [];
            if (!empty($row['signals_json'])) {
                $signals = json_decode((string) $row['signals_json'], true) ?: [];
            }
            $attributes = [];
            if (!empty($row['attributes_json'])) {
                $attributes = json_decode((string) $row['attributes_json'], true) ?: [];
            }

            $window[] = new HistoryRecord(
                (int) $row['ts_ms'],
                (bool) (int) $row['success'],
                is_array($signals) ? $signals : [],
                (int) ($row['duration_ms'] ?? 0),
                is_array($attributes) ? $attributes : []
            );
        }

        // CircuitHistory expects window in chronological order (oldest first) for some policies.
        return new CircuitHistory([], array_reverse($window));
    }

    public function record(CircuitKey $key, HistoryRecord $record): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->tableName} (circuit_key, ts_ms, success, signals_json, duration_ms, attributes_json)
            VALUES (:key, :ts, :success, :signals, :duration, :attributes)
        ");

        $stmt->execute([
            'key' => $key->id(),
            'ts' => $record->tsMs,
            'success' => $record->success ? 1 : 0,
            'signals' => json_encode($record->signals),
            'duration' => $record->durationMs,
            'attributes' => json_encode($record->attributes),
        ]);
    }
}

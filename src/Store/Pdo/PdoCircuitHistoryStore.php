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
            ORDER BY recorded_at_ms DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':key', $key->id());
        $stmt->bindValue(':limit', $this->retentionLimit, PDO::PARAM_INT);
        $stmt->execute();

        $records = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $records[] = new HistoryRecord(
                (string) $row['outcome'],
                (int) $row['recorded_at_ms'],
                json_decode($row['details_json'], true) ?: []
            );
        }

        // CircuitHistory expects records in chronological order (oldest first) for some policies
        return new CircuitHistory(array_reverse($records));
    }

    public function record(CircuitKey $key, HistoryRecord $record): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->tableName} (circuit_key, outcome, recorded_at_ms, details_json)
            VALUES (:key, :outcome, :at, :details)
        ");

        $stmt->execute([
            'key' => $key->id(),
            'outcome' => $record->outcome,
            'at' => $record->recordedAtMs,
            'details' => json_encode($record->details)
        ]);
    }
}

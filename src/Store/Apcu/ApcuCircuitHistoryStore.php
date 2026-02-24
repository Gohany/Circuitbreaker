<?php

namespace Gohany\Circuitbreaker\Store\Apcu;

use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Store\CircuitHistory;
use Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface;
use Gohany\Circuitbreaker\Store\HistoryRecord;

final class ApcuCircuitHistoryStore implements CircuitHistoryStoreInterface
{
    private string $prefix;
    private int $retentionLimit;
    private int $ttlSeconds;

    private function assertApcuAvailable(): void
    {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_entry')) {
            throw new \RuntimeException('APCu is not available: please install/enable ext-apcu.');
        }
    }

    public function __construct(string $prefix = 'cb:history:', int $retentionLimit = 100, int $ttlSeconds = 604800)
    {
        $this->prefix = $prefix;
        $this->retentionLimit = $retentionLimit;
        $this->ttlSeconds = $ttlSeconds;
    }

    public function getHistory(CircuitKey $key): CircuitHistory
    {
        $this->assertApcuAvailable();
        $k = $this->prefix . $key->id();
        $records = apcu_fetch($k);

        if (!is_array($records)) {
            return new CircuitHistory([]);
        }

        $historyRecords = [];
        foreach ($records as $r) {
            $historyRecords[] = new HistoryRecord($r['outcome'], $r['at'], $r['details']);
        }

        return new CircuitHistory($historyRecords);
    }

    public function record(CircuitKey $key, HistoryRecord $record): void
    {
        $this->assertApcuAvailable();
        $k = $this->prefix . $key->id();
        
        $newEntry = [
            'outcome' => $record->outcome,
            'at' => $record->recordedAtMs,
            'details' => $record->details,
        ];

        apcu_entry($k, function($key, $existing) use ($newEntry) {
            if (!is_array($existing)) {
                $existing = [];
            }
            $existing[] = $newEntry;
            
            if (count($existing) > $this->retentionLimit) {
                array_shift($existing);
            }
            
            return $existing;
        }, $this->ttlSeconds);
    }
}

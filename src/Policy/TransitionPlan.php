<?php

namespace Gohany\Circuitbreaker\Policy;

use Gohany\Circuitbreaker\Store\CircuitState;
use Gohany\Circuitbreaker\Store\HistoryRecord;
use Gohany\Circuitbreaker\SideEffect\SideEffectRequest;

final class TransitionPlan
{
    public ?CircuitState $newState;
    public ?HistoryRecord $historyRecord;
    /** @var SideEffectRequest[] */
    public array $sideEffects;
    /** @var array<string,mixed> */
    public array $attributes;

    /**
     * @param SideEffectRequest[] $sideEffects
     * @param array<string,mixed> $attributes
     */
    public function __construct(?CircuitState $newState, ?HistoryRecord $historyRecord, array $sideEffects = [], array $attributes = [])
    {
        $this->newState = $newState;
        $this->historyRecord = $historyRecord;
        $this->sideEffects = $sideEffects;
        $this->attributes = $attributes;
    }
}

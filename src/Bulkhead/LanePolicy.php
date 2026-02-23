<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Bulkhead;

final class LanePolicy
{
    /** @var string */
    private $lane;
    /** @var int|null */
    private $maxConcurrent;
    /** @var float|null */
    private $percent;
    /** @var int|null */
    private $weight;

    private function __construct(string $lane, ?int $maxConcurrent, ?float $percent, ?int $weight)
    {
        $this->lane = $lane;
        $this->maxConcurrent = $maxConcurrent;
        $this->percent = $percent;
        $this->weight = $weight;
    }

    public static function fixed(string $lane, int $maxConcurrent): self
    {
        return new self($lane, $maxConcurrent, null, null);
    }

    public static function percent(string $lane, float $percent): self
    {
        return new self($lane, null, $percent, null);
    }

    public static function weight(string $lane, int $weight): self
    {
        return new self($lane, null, null, $weight);
    }

    public function getLane(): string
    {
        return $this->lane;
    }

    public function getMaxConcurrent(): ?int
    {
        return $this->maxConcurrent;
    }

    public function getPercent(): ?float
    {
        return $this->percent;
    }

    public function getWeight(): ?int
    {
        return $this->weight;
    }
}

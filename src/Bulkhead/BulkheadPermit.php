<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Bulkhead;

use Gohany\Circuitbreaker\Contracts\BulkheadPermitInterface;

final class BulkheadPermit implements BulkheadPermitInterface
{
    /** @var string */
    private $id;
    /** @var string */
    private $lane;
    /** @var callable */
    private $releaser;
    /** @var bool */
    private $released = false;

    /**
     * @param callable(string $lane, string $permitId):void $releaser
     */
    public function __construct(string $id, string $lane, callable $releaser)
    {
        $this->id = $id;
        $this->lane = $lane;
        $this->releaser = $releaser;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        ($this->releaser)($this->lane, $this->id);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLane(): string
    {
        return $this->lane;
    }
}

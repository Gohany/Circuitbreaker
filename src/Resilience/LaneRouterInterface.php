<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

interface LaneRouterInterface
{
    public function laneFor(Context $ctx): string;
}

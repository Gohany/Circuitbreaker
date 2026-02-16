<?php

namespace Gohany\Circuitbreaker\Consts;

final class CircuitStateMode
{
    public const CLOSED = 'closed';
    public const OPEN = 'open';
    public const HALF_OPEN = 'half_open';

    private function __construct() {}
}

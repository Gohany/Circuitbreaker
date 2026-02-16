<?php

namespace Gohany\Circuitbreaker\Policy\Http;

class DefaultHttpCircuitPolicy extends AbstractHttpCircuitPolicy
{
    public function name()
    {
        return 'http_default';
    }
}

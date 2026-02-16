<?php

namespace Gohany\Rtry\Impl\Parts;

class Part
{

    const KEY = '';

    protected static function trimKey(string $value): string
    {
        $value = trim($value, ';');
        return (strpos($value, static::KEY . '=') === 0) ? substr($value, strlen(static::KEY) + 1) : $value;
    }

}
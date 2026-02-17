<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Sanity\Output;

use Gohany\Circuitbreaker\SideEffect\SideEffectRequest;

interface SanityCheckOutputInterface
{
    public function heading(string $title): void;

    public function info(string $message): void;

    public function step(string $title): void;

    public function pass(string $message): void;

    public function fail(string $message): void;

    public function sideEffect(SideEffectRequest $request): void;
}

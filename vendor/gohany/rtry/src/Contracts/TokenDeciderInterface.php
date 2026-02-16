<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

interface TokenDeciderInterface
{
    public function setTokens(array $tokens): self;
    public function getTokens(): array;
}
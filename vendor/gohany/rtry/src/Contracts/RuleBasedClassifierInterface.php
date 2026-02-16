<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

interface RuleBasedClassifierInterface
{
    public function addRule(RuleInterface $rule): self;
    public function hasRuleOfType(string $fqcn): bool;
}
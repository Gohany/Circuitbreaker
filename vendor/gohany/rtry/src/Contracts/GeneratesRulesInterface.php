<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

interface GeneratesRulesInterface
{
    public function addRulesToClassifier(?RuleBasedClassifierInterface $classifier): RuleBasedClassifierInterface;
}
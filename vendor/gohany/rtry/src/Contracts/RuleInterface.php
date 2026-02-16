<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

interface RuleInterface
{
    /**
     * @param \Throwable $e
     * @return FailureMetadataInterface|null Return metadata if the rule matches, or null to fall through.
     */
    public function apply(\Throwable $e): ?FailureMetadataInterface;
}

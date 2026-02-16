<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

interface FailureClassifierInterface
{
    /**
     * Classify a Throwable into an optional status code and a set of tags.
     *
     * @param \Throwable $e
     * @return FailureMetadataInterface
     */
    public function classify(\Throwable $e): FailureMetadataInterface;
}

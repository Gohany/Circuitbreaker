<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

interface FailureMetadataInterface
{
    public function getStatusCode(): ?int;

    /** @return array<int,string> */
    public function getTags(): array;

    /**
     * Optional associative array merged into the attempt context for NEXT attempt.
     * Later values overwrite earlier keys.
     *
     * @return array<string,mixed>
     */
    public function getContextPatch(): array;

    /**
     * Minimum delay (milliseconds) to observe before the next attempt.
     * If null, no minimum override is requested.
     */
    public function getMinNextDelayMs(): ?int;

    /**
     * If set, do not start the next attempt before this UNIX epoch time in milliseconds.
     * Use this for Retry-After (HTTP-date) or absolute rate-limit resets.
     */
    public function getNotBeforeUnixMs(): ?int;

    /**
     * Raw headers captured (e.g. from a PSR-7 Response), useful for hooks/telemetry.
     * Shape: header-name (lowercased) => list-of-values
     *
     * @return array<string, array<int,string>>
     */
    public function getHeaders(): array;
}
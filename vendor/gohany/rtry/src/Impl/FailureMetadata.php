<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Rtry\Contracts\FailureMetadataInterface;

final class FailureMetadata implements FailureMetadataInterface
{
    /** @var array<int,string> */
    private array $tags;

    private ?int $statusCode;

    /** @var array<string,mixed> */
    private array $ctxPatch;

    private ?int $minNextDelayMs;

    private ?int $notBeforeUnixMs;

    /** @var array<string, array<int,string>> */
    private array $headers;

    /**
     * @param array<int,string> $tags
     * @param array<string,mixed> $ctxPatch
     * @param array<string, array<int,string>> $headers
     */
    public function __construct(
        ?int $statusCode,
        array $tags = [],
        array $ctxPatch = [],
        ?int $minNextDelayMs = null,
        ?int $notBeforeUnixMs = null,
        array $headers = []
    ) {
        $this->statusCode = $statusCode;
        $this->tags = array_values(array_unique($tags));
        $this->ctxPatch = $ctxPatch;
        $this->minNextDelayMs = $minNextDelayMs;
        $this->notBeforeUnixMs = $notBeforeUnixMs;
        $this->headers = $headers;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /** @return array<int,string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @return array<string,mixed> */
    public function getContextPatch(): array
    {
        return $this->ctxPatch;
    }

    public function getMinNextDelayMs(): ?int
    {
        return $this->minNextDelayMs;
    }

    public function getNotBeforeUnixMs(): ?int
    {
        return $this->notBeforeUnixMs;
    }

    /** @return array<string, array<int,string>> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

}
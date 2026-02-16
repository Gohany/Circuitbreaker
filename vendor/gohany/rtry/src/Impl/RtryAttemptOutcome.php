<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\AttemptOutcomeInterface;
use Throwable;

final class RtryAttemptOutcome implements AttemptOutcomeInterface
{
    private $result;

    private ?Throwable $error;

    private ?int $statusCode;

    private array $tags = [];

    public function __construct($result = null, ?Throwable $error = null, ?int $statusCode = null, array $tags = [])
    {
        $this->result = $result;
        $this->error = $error;
        $this->statusCode = $statusCode;
        $this->tags = $tags;
    }

    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    public function getResult()
    {
        return $this->result;
    }

    public function getError(): ?Throwable
    {
        return $this->error;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setError(?Throwable $error): self
    {
        $this->error = $error;
        return $this;
    }

    public function result()
    {
        return $this->getResult();
    }

    public function error(): ?Throwable
    {
        return $this->getError();
    }

    public function statusCode(): ?int
    {
        return $this->getStatusCode();
    }

    public function tags(): array
    {
        return $this->getTags();
    }
}
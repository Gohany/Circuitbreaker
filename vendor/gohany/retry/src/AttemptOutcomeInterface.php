<?php declare(strict_types=1);

namespace Gohany\Retry;

interface AttemptOutcomeInterface
{
    /** True when the operation returned successfully. */
    public function isSuccess(): bool;

    /** @return mixed|null Value on success. */
    public function result();

    /** Error on failure, or null when successful. */
    public function error(): ?\Throwable;

    /** Optional numeric status (e.g., HTTP code) for decider logic. */
    public function statusCode(): ?int;

    /** Adapter tags (e.g., 'ETIMEDOUT', '5xx', etc.). @return array<int,string> */
    public function tags(): array;
}

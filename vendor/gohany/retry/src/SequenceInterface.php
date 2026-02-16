<?php

namespace Gohany\Retry;

interface SequenceInterface
{
    /**
     * @return list<int> The configured delay sequence in milliseconds.
     */
    public function delaysMs(): array;

    /**
     * Whether the last delay should be repeated indefinitely when the sequence ends.
     */
    public function isRepeatLastDelay(): bool;

    /**
     * Resets the internal iteration state to the beginning of the sequence.
     */
    public function reset(): void;

    /**
     * Returns the next delay in milliseconds, or null if there is none.
     */
    public function nextDelayMs(): ?int;

}
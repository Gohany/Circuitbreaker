<?php declare(strict_types=1);

namespace Gohany\Rtry\Contracts;

use Gohany\Retry\HedgeInterface;
use Gohany\Retry\JitterInterface;
use Gohany\Retry\RetryDeciderInterface;
use Gohany\Retry\RetryPolicyInterface;
use Gohany\Retry\SequenceInterface;

interface RtryPolicyInterface extends RetryPolicyInterface
{
    public const BACKOFF_MODE_LINEAR = 'lin';
    public const BACKOFF_MODE_EXPONENTIAL = 'exp';
    public const BACKOFF_MODE_SEQUENCE = 'seq';
    public function setCapMs(int $milliseconds): self;

    public function setAttempts(int $attempts): self;

    public function setAttemptTimeoutMs(?int $milliseconds): self;

    public function setDeadlineBudgetMs(?int $milliseconds): self;

    public function setStartAfterMs(int $milliseconds): self;

    public function setBackoffMode(string $mode): self;

    public function setExponentialBase(?float $base): self;

    public function setDelayMs(?int $milliseconds): self;

    public function setFollowHeaders(bool $follow): self;

    public function setSequence(SequenceInterface $sequence): self;

    public function setHedgeSpec(HedgeInterface $spec): self;

    public function setJitterSpec(JitterInterface $jitter): self;

    public function setRetryDecider(RetryDeciderInterface $decider): self;

    public function setCanonicalSpec(string $specification): self;

    public function setRetryOnTokens(array $tokens): self;

    public function setFailureClassifier(?FailureClassifierInterface $classifier): self;

    public function failureClassifier(): ?FailureClassifierInterface;

    public function backoffMode(): string;

    public function exponentialBase(): ?float;

    public function canonicalSpec(): string;

    public function setSeed(?int $seed): self;

    public function resetCursor(): void;
}
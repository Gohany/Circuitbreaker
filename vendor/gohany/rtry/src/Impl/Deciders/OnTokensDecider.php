<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Deciders;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\AttemptOutcomeInterface;
use Gohany\Retry\RetryDeciderInterface;
use Gohany\Rtry\Contracts\TokenDeciderInterface;

final class OnTokensDecider implements RetryDeciderInterface, TokenDeciderInterface
{
    /** @var array<int,string|int> */
    private array $tokens;

    /**
     * @param array<int,string|int> $tokens e.g., ['5xx', '429', 'ETIMEDOUT', 'NETWORK_ERROR']
     */
    public function __construct(array $tokens)
    {
        $this->tokens = array_map(function ($t) {
            return is_int($t) ? $t : strtoupper((string) $t);
        }, $tokens);
    }

    public function setTokens(array $tokens): self
    {
        $this->tokens = array_map(function ($t) {
            return is_int($t) ? $t : strtoupper((string) $t);
        }, $tokens);
        return $this;
    }

    public function getTokens(): array
    {
        return $this->tokens;
    }

    public function shouldRetry(AttemptOutcomeInterface $outcome, AttemptContextInterface $context): bool
    {
        if ($outcome->isSuccess()) {
            return false;
        }

        $status = $outcome->statusCode();
        $tags   = array_map('strtoupper', $outcome->tags());

        foreach ($this->tokens as $tag) {
            // exact code
            if (is_int($tag) && $status === $tag) {
                return true;
            }

            if (is_string($tag)) {
                // 5xx / 4xx
                $tag = strtoupper($tag);
                if (($tag === '5XX' && $status !== null && (int) floor($status / 100) === 5) ||
                    ($tag === '4XX' && $status !== null && (int) floor($status / 100) === 4)) {
                    return true;
                }
                if (in_array($tag, $tags, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}

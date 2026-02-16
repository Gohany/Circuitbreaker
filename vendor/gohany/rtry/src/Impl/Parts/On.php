<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Rtry\Contracts\GeneratesRulesInterface;
use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;
use Gohany\Rtry\Contracts\RuleBasedClassifierInterface;
use Gohany\Rtry\Impl\FailureToken;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Impl\Rules\RateLimitBackoffRule;

/**
 * on=<codes> (free-form string preserved as-is)
 */
final class On extends Part implements PartInterface, GeneratesRulesInterface
{
    public const KEY = 'on';
    private array $tokens;

    public function __construct(array $tokens) {
        $this->tokens = array_values(array_filter(array_map('trim', $tokens), fn($t) => $t !== ''));
    }

    public function tokens(): array
    {
        return $this->tokens;
    }

    public function key(): string
    {
        return On::KEY;
    }

    public function __toString(): string
    {
        return On::KEY . '=' . implode(',', $this->tokens);
    }

    public function applyToPolicy(RtryPolicyInterface $policy): RtryPolicyInterface
    {
        $tokens = [];
        if (empty($tokens)) {
            $tokens[] = 'default';
        } else {
            foreach ($tokens as $token) {
                if ($this->isDefaultTag($token)) {
                    $tokens = array_merge($tokens, FailureToken::defaults());
                    break;
                }
            }
        }
        return $policy->setRetryOnTokens($tokens);
    }

    public function addRulesToClassifier(?RuleBasedClassifierInterface $classifier): RuleBasedClassifierInterface
    {

        if ($classifier === null) {
            $classifier = new RuleBasedFailureClassifier();
        }

        foreach($this->tokens as $token) {

            if ($this->matchesRateLimitSpec($token) && !$classifier->hasRuleOfType(RateLimitBackoffRule::class)) {
                $classifier->addRule(new RateLimitBackoffRule());
            }

        }

        return $classifier;
    }

    private function isDefaultTag(string $tag): bool
    {
        return in_array(strtolower($tag), ['default', 'standard'], true);
    }

    private function matchesRateLimitSpec(string $tag): bool
    {
        // Normalize: uppercase and strip non-alphanumeric for easy matching
        $norm = strtoupper($tag);
        $norm = (string)preg_replace('/[^A-Z0-9]/', '', $norm);

        if ($norm === '429' || strpos($norm, '429') !== false) {
            return true;
        }

        // Common synonyms and variations
        if (strpos($norm, 'RATELIMIT') !== false) {
            return true;
        }
        if (strpos($norm, 'TOOMANYREQUESTS') !== false) {
            return true;
        }
        if (strpos($norm, 'THROTTL') !== false) { // THROTTLE / THROTTLED / THROTTLING
            return true;
        }

        // Heuristic: both RATE and LIMIT words present (after stripping separators)
        if (strpos($norm, 'RATE') !== false && strpos($norm, 'LIMIT') !== false) {
            return true;
        }

        return false;
    }

    public static function make(string $value): PartInterface
    {
        return new On(explode(',', On::trimKey($value)));
    }

}
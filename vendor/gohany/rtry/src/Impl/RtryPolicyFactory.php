<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Retry\RetryDeciderInterface;
use Gohany\Retry\RetryPolicyFactoryInterface;
use Gohany\Rtry\Contracts\GeneratesRulesInterface;
use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;
use Gohany\Rtry\Impl\Deciders\AlwaysRetryDecider;
use Gohany\Rtry\Impl\Parts\Attempts;
use Gohany\Rtry\Impl\Parts\Base;
use Gohany\Rtry\Impl\Parts\Cap;
use Gohany\Rtry\Impl\Parts\Deadline;
use Gohany\Rtry\Impl\Parts\Delay;
use Gohany\Rtry\Impl\Parts\FollowHeaders;
use Gohany\Rtry\Impl\Parts\Hedge;
use Gohany\Rtry\Impl\Parts\Jitter;
use Gohany\Rtry\Impl\Parts\Mode;
use Gohany\Rtry\Impl\Parts\On;
use Gohany\Rtry\Impl\Parts\Sequence;
use Gohany\Rtry\Impl\Parts\StartAfter;
use Gohany\Rtry\Impl\Parts\Timeout;

final class RtryPolicyFactory implements RetryPolicyFactoryInterface
{
    public const KEY = 'rtry';

    private ?RetryDeciderInterface $deciderOverride;

    /**
     * The last parsed parts from fromSpec(), in the exact order encountered in input.
     * Useful for rebuilding a normalized spec string by joining (string)$part.
     *
     * @var list<PartInterface>
     */
    private array $lastParts = [];

    public function __construct(?RetryDeciderInterface $deciderOverride = null)
    {
        $this->deciderOverride = $deciderOverride;
    }

    /**
     * Access the last parsed spec parts from fromSpec().
     *
     * @return list<PartInterface>
     */
    public function getLastParts(): array
    {
        return $this->lastParts;
    }

    public function fromSpec(string $spec, ?RetryDeciderInterface $decider = null): RtryPolicyInterface
    {
        $s = trim($spec);
        if (stripos($s, self::KEY . ':') === 0) {
            $s = substr($s, 5);
        }

        if ($s === '') {
            throw new \InvalidArgumentException('Empty rtry spec.');
        }

        // Use provided decider or overrides
        $decider = $this->deciderOverride ?? $decider ?? new AlwaysRetryDecider();

        $policy = new RtryPolicy();
        $policy->setRetryDecider($decider);

        $pairs = array_map('trim', explode(';', $s));
        $map = [];
        $orderedParts = [];

        foreach ($pairs as $pair) {

            if ($pair === '') {
                continue;
            }

            $pos = strpos($pair, '=');
            if ($pos === false) {
                throw new \InvalidArgumentException('Malformed pair: ' . $pair);
            }

            $k = strtolower(trim(substr($pair, 0, $pos)));
            $v = trim(substr($pair, $pos + 1));

            if ($k === '') {
                throw new \InvalidArgumentException('Empty key in pair: ' . $pair);
            }

            if (isset($map[$k])) {
                throw new \InvalidArgumentException('Duplicate key: ' . $k);
            }

            $map[$k] = $v;
            $orderedParts[] = $this->makePart($k, $v);

        }

        $this->lastParts = $orderedParts;

        foreach ($orderedParts as $part) {

            if ($part instanceof PartInterface) {
                $part->applyToPolicy($policy);
            }

            if ($part instanceof GeneratesRulesInterface) {
                $part->addRulesToClassifier($policy->failureClassifier());
            }

        }

        return $policy;

    }

    public function toSpec(): string
    {
        if (empty($this->lastParts)) {
            throw new \RuntimeException('No spec parts parsed yet. Call fromSpec() first.');
        }
        $parts = array_map(fn(PartInterface $p) => (string)$p, $this->lastParts);
        return 'rtry:' . implode(';', $parts);
    }

    private function makePart(string $key, string $value): PartInterface
    {
        switch ($key) {
            case Attempts::KEY:
                return Attempts::make($value);
            case Delay::KEY:
                return Delay::make($value);
            case Mode::KEY:
                return Mode::make($value);
            case Base::KEY:
                return Base::make($value);
            case Cap::KEY:
                return Cap::make($value);
            case Timeout::KEY:
                return Timeout::make($value);
            case Deadline::KEY:
                return Deadline::make($value);
            case On::KEY:
                return On::make($value);
            case StartAfter::KEY:
                return StartAfter::make($value);
            case FollowHeaders::KEY:
                return FollowHeaders::make($value);
            case Sequence::KEY:
                return Sequence::make($value);
            case Jitter::KEY:
                return Jitter::make($value);
            case Hedge::KEY:
                return Hedge::make($value);
            default:
                throw new \InvalidArgumentException('Unknown ' . self::KEY . ' key: ' . $key);
        }
    }

}
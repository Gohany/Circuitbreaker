<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;
use Gohany\Retry\SequenceInterface;
use Gohany\Rtry\Impl\Duration;

final class Sequence extends Part implements PartInterface, SequenceInterface
{
    public const KEY = 'seq';
    private array $delaysMs;
    private bool $repeatLast;
    private int $cursor = 0;

    /**
     * @param list<int> $delaysMs
     * @param bool $repeatLast Whether to repeat the last delay indefinitely
     */
    public function __construct(array $delaysMs, bool $repeatLast)
    {
        $this->delaysMs = $delaysMs;
        $this->repeatLast = $repeatLast;
    }

    public function key(): string
    {
        return Sequence::KEY;
    }

    public function __toString(): string
    {
        $tokens = array_map(static fn (int $ms) => Duration::formatMs($ms), $this->delaysMs);
        return Sequence::KEY . '=' . implode(',', $tokens) . ($this->repeatLast ? '*' : '');
    }

    public function applyToPolicy(RtryPolicyInterface $policy): RtryPolicyInterface
    {
        return $policy->setSequence($this);
    }

    public function delaysMs(): array
    {
        return $this->delaysMs;
    }

    public function isRepeatLastDelay(): bool
    {
        return $this->repeatLast;
    }

    public function reset(): void
    {
        $this->cursor = 0;
    }

    public function nextDelayMs(): ?int
    {
        $count = count($this->delaysMs);

        if ($count === 0) {
            return null;
        }

        if ($this->cursor < $count) {
            return $this->delaysMs[$this->cursor++];
        }

        if ($this->isRepeatLastDelay()) {
            $this->cursor = $count;
            return $this->delaysMs[$count - 1];
        }

        return null;
    }

    public function delayByPosition(int $index): ?int
    {
        $count = count($this->delaysMs);
        $position = $index - 1;

        if ($position < 0) {
            return null;
        }

        if ($position >= $count) {
            if ($this->isRepeatLastDelay()) {
                return $this->delaysMs[$count - 1];
            }
            return null;
        }

        return $this->delaysMs[$index - 1];
    }

    /**
     * Parses seq=(<dur>[,<dur>...][,*]) or raw "(...)" body.
     *
     * @return array{0:list<int>,1:bool}
     */
    public static function make(string $value): Sequence
    {
        $v = trim(Sequence::trimKey($value));

        // Allow both: "seq=(...)" and "(...)"
        $prefix = self::KEY . '=';
        if (strncasecmp($v, $prefix, strlen($prefix)) === 0) {
            $v = ltrim(substr($v, strlen($prefix)));
        }

        // Parentheses are optional: if present, unwrap; otherwise, parse as-is.
        if ($v !== '' && $v[0] === '(' && substr($v, -1) === ')') {
            $v = substr($v, 1, -1);
        }

        $v = trim($v);
        if ($v === '') {
            throw new \InvalidArgumentException('seq cannot be empty');
        }

        $rawParts = array_map('trim', explode(',', $v));
        $repeat   = false;

        // Trailing "*" may be either its own element or attached to the last token
        $lastIdx = count($rawParts) - 1;
        if ($rawParts[$lastIdx] === '*') {
            $repeat = true;
            array_pop($rawParts);
        } else {
            $last = $rawParts[$lastIdx];
            if ($last !== '' && substr($last, -1) === '*') {
                $repeat = true;
                $rawParts[$lastIdx] = substr($last, 0, -1);
            }
        }

        $delays = [];
        foreach ($rawParts as $p) {
            if ($p === '') {
                throw new \InvalidArgumentException('Empty element in seq: ' . $value);
            }
            // No unit => ms. Supports decimals + ms/s/m/h via Duration::parseDurationMs
            $delays[] = Duration::parseDurationMs($p);
        }

        if ($repeat && $delays === []) {
            throw new \InvalidArgumentException('seq cannot be only "*"');
        }

        return new Sequence($delays, $repeat);
    }

}
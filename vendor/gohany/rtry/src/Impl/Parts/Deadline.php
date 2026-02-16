<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;
use Gohany\Rtry\Impl\Duration;

final class Deadline extends Part implements PartInterface
{
    public const KEY = 'dl';
    private int $deadlineMs;

    public function __construct(int $deadlineMs) {
        $this->deadlineMs = $deadlineMs;
    }

    public function deadlineMs(): int
    {
        return $this->deadlineMs;
    }

    public function key(): string
    {
        return Deadline::KEY;
    }

    public function __toString(): string
    {
        return Deadline::KEY . '=' . Duration::formatMs($this->deadlineMs);
    }

    public function applyToPolicy(RtryPolicyInterface $policy): RtryPolicyInterface
    {
        return $policy->setDeadlineBudgetMs($this->deadlineMs);
    }

    public static function make(string $value): PartInterface
    {
        // if is an int followed by 'ms', 's', 'm', or 'h', parse as duration
        $v = strtolower(trim(Deadline::trimKey($value)));
        if (preg_match('/^([0-9]+)(ms|s|m|h)?$/', $v)) {
            return new Deadline(Duration::parseDurationMs($value));
        } elseif (ctype_digit($v)) {
            // Plain integer, assume ms
            return new Deadline(intval($v));
            // else if is a datetime string
        } else {

            try {
                $now = new \DateTimeImmutable($v);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid deadline datetime: ' . $value);
            }

            $diff = $now->getTimestamp() - time();
            if ($diff <= 0) {
                throw new \InvalidArgumentException('Deadline must be in the future: ' . $value);
            }
            return new Deadline($diff * 1000);

        }
    }

}
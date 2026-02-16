<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;

final class Mode extends Part implements PartInterface
{
    public const KEY = 'm';
    public const BACKOFF_MODE_TYPES = [
        RtryPolicyInterface::BACKOFF_MODE_EXPONENTIAL,
        RtryPolicyInterface::BACKOFF_MODE_LINEAR,
        RtryPolicyInterface::BACKOFF_MODE_SEQUENCE,
    ];
    private string $mode;

    public function __construct(string $mode) {
        $this->mode = $mode;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function key(): string
    {
        return Mode::KEY;
    }

    public function __toString(): string
    {
        return Mode::KEY . '=' . $this->mode;
    }

    public function applyToPolicy(RtryPolicyInterface $policy): RtryPolicyInterface
    {
        return $policy->setBackoffMode($this->mode);
    }

    public static function make(string $value): PartInterface
    {
        $mode = strtolower(Mode::trimKey($value));
        if (!in_array($mode, self::BACKOFF_MODE_TYPES, true)) {
            throw new \InvalidArgumentException('Unknown mode: ' . $value);
        }
        return new Mode($mode);
    }
}
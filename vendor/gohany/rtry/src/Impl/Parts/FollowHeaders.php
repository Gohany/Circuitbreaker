<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;

class FollowHeaders extends Part implements PartInterface
{
    public const KEY = 'fh';
    private bool $followHeaders;

    public function __construct(bool $followHeader = true)
    {
        $this->followHeaders = $followHeader;
    }

    public function key(): string
    {
        return FollowHeaders::KEY;
    }

    public function value(): bool
    {
        return $this->followHeaders;
    }

    public function __toString(): string
    {
        return FollowHeaders::KEY . '=' . ($this->followHeaders ? '1' : '0');
    }

    public function applyToPolicy(RtryPolicyInterface $policy): RtryPolicyInterface
    {
        return $policy->setFollowHeaders($this->followHeaders);
    }

    public static function make(string $value): PartInterface
    {
        return new FollowHeaders(boolval(FollowHeaders::trimKey($value)));
    }

}
<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Retry\HedgeInterface;
use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;
use Gohany\Rtry\Impl\Duration;

/**
 * h=<n>@<delay>
 */
final class Hedge extends Part implements PartInterface, HedgeInterface
{
    public const KEY = 'h';
    private int $lanes;
    private int $delayMs;
    private int $cancelPolicy;

    public function __construct(int $lanes, int $delayMs, ?int $cancelPolicy) {
        $this->delayMs = $delayMs;
        $this->lanes = $lanes;
        $this->cancelPolicy = $cancelPolicy ?: self::CANCEL_ON_FIRST_SUCCESS;
    }

    public function key(): string
    {
        return Hedge::KEY;
    }

    public function __toString(): string
    {
        return Hedge::KEY . '=' . $this->lanes . '@' . Duration::formatMs($this->delayMs) .
            ($this->cancelPolicy !== self::CANCEL_ON_FIRST_SUCCESS ? '&' . $this->cancelPolicy : '');
    }

    public function cancelPolicy(): int
    {
        return $this->cancelPolicy;
    }

    public function staggerDelayMs(): int
    {
        return $this->delayMs;
    }

    public function lanes(): int
    {
        return $this->lanes;
    }

    public function applyToPolicy(RtryPolicyInterface $policy): RtryPolicyInterface
    {
        return $policy->setHedgeSpec($this);
    }

    /**
     * h=<n>@<delay>
     *
     * @return array{0:int,1:int,2:int|null}
     */
    public static function make(string $value): Hedge
    {
        $v = trim(Hedge::trimKey($value));
        $pos = strpos($v, '@');
        if ($pos === false) {
            throw new \InvalidArgumentException('Invalid hedging format, expected n@delay or n@delay&policy: ' . $value);
        }

        $nToken = trim(substr($v, 0, $pos));
        $delayToken = trim(substr($v, $pos + 1));

        $n = (int)$nToken;
        if ($n < 1) {
            throw new \InvalidArgumentException('Hedge count must be >= 1: ' . $value);
        }

        // Optional "&<int>" cancellation policy after the delay token
        $cancelPolicy = null;
        $ampPos = strpos($delayToken, '&');
        if ($ampPos !== false) {
            $delayPart = trim(substr($delayToken, 0, $ampPos));
            $cancelPart = trim(substr($delayToken, $ampPos + 1));

            if ($cancelPart === '' || !ctype_digit($cancelPart)) {
                throw new \InvalidArgumentException('Invalid cancellation policy, expected an integer after "&": ' . $value);
            }

            $delayMs = Duration::parseDurationMs($delayPart);
            $cancelPolicy = (int)$cancelPart;
        } else {
            $delayMs = Duration::parseDurationMs($delayToken);
        }

        return new Hedge($n, $delayMs, $cancelPolicy);
    }

}
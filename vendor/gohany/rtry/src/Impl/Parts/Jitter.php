<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Parts;

use Gohany\Retry\JitterInterface;
use Gohany\Rtry\Contracts\PartInterface;
use Gohany\Rtry\Contracts\RtryPolicyInterface;
use Gohany\Rtry\Impl\Duration;

class Jitter extends Part implements PartInterface, JitterInterface
{

    public const KEY = 'j';

    private ?int $windowMs;

    private string $mode;
    private ?float $percent;


    public function __construct(?int $windowMs, string $mode = 'full', ?float $percent = null)
    {
        if ($windowMs < 0 && ($percent === null || $percent < 0)) {
            throw new \InvalidArgumentException('Jitter window must be >= 0');
        }
        $mode = strtolower($mode);
        if ($mode !== 'full' && $mode !== 'pm') {
            throw new \InvalidArgumentException('Invalid jitter mode: ' . $mode);
        }
        if ($percent !== null) {
            if (!is_finite($percent) || $percent < 0.0 || $percent > 100.0) {
                throw new \InvalidArgumentException('Jitter percent must be between 0 and 100');
            }
        }

        $this->windowMs = $percent === null ? $windowMs : null; // exclusivity
        $this->mode = $mode;
        $this->percent = $percent;
    }


    public function mode(): string
    {
        return $this->mode;
    }

    public function percent(): ?float
    {
        return $this->percent;
    }

    public function windowMs(): ?int
    {
        return $this->windowMs;
    }

    public function apply(int $nominalDelayMs, ?int $seed = null): int
    {
        if ($nominalDelayMs <= 0) {
            return 0;
        }

        $window = $this->percent !== null
            ? (int) round(($this->percent / 100.0) * $nominalDelayMs)
            : $this->windowMs ?? 0;

        if ($window <= 0) {
            return $this->mode === 'pm' ? $nominalDelayMs : 0;
        }

        if ($this->mode === 'pm') {
            $delta = $this->randInRange(-$window, $window, $seed, $nominalDelayMs);
            $v = $nominalDelayMs + $delta;
            return max($v, 0);
        }

        // 'full' mode
        return $this->randInRange(0, $window, $seed, $nominalDelayMs);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function __toString(): string
    {
        $value = $this->percent !== null
            ? Duration::formatPercent($this->percent)
            : Duration::formatMs($this->windowMs);

        return $this->mode === 'full'
            ? self::KEY . '=' . $value
            : self::KEY . '=' . $value . '@pm';
    }

    public function applyToPolicy(RtryPolicyInterface $policy): RtryPolicyInterface
    {
        return $policy->setJitterSpec($this);
    }

    /**
     * Deterministic-ish range when $seed is provided; otherwise random_int().
     */
    private function randInRange(int $min, int $max, ?int $seed, int $mix): int
    {
        if ($max <= $min) {
            return $min;
        }

        if ($seed === null) {
            try {
                return random_int($min, $max);
            } catch (\Throwable $e) {
                $u = mt_rand() / max(1, mt_getrandmax());
                return $min + (int)floor($u * ($max - $min + 1));
            }
        }

        // Simple deterministic hash -> [0,1)
        $h = sprintf('%u', crc32($seed . ':' . $mix . ':' . self::KEY . ':' . $this->mode));
        $frac = ((int) $h % 1000000) / 1000000.0; // 6 digits of precision
        return $min + (int) floor(($max - $min + 1) * $frac);
    }

    public static function make(string $value): Jitter
    {
        $v = trim(Jitter::trimKey($value));
        if ($v === '' || $v === '0' || $v === '0%') {
            // Default: no jitter, full mode. 0% is effectively no jitter.
            return new Jitter(null, 'full', $v === '0%' ? 0.0 : null);
        }

        $mode = 'full';
        $durToken = $v;

        $at = strpos($v, '@');
        if ($at !== false) {
            $durToken = trim(substr($v, 0, $at));
            $modeToken = strtolower(trim(substr($v, $at + 1)));
            if ($modeToken !== '') {
                if ($modeToken !== 'full' && $modeToken !== 'pm') {
                    throw new \InvalidArgumentException('Invalid jitter mode "' . $modeToken . '". Expected "full" or "pm".');
                }
                $mode = $modeToken;
            }
        }

        $windowMs = null;
        $percent = null;

        if ($durToken !== '' && $durToken !== '0') {
            // Support percentage e.g. "15%" or "12.5%"
            if (substr($durToken, -1) === '%') {
                $num = trim(substr($durToken, 0, -1));
                if ($num === '' || !is_numeric($num)) {
                    throw new \InvalidArgumentException('Invalid jitter percent: ' . $value);
                }
                $percent = (float)$num;
                if ($percent < 0 || $percent > 100) {
                    throw new \InvalidArgumentException('Jitter percent must be between 0 and 100: ' . $value);
                }
                $windowMs = null;
            } else {
                // Duration in ms|s|m|h etc.
                $windowMs = Duration::parseDurationMs($durToken);
                if ($windowMs < 0) {
                    throw new \InvalidArgumentException('Jitter window must be >= 0: ' . $value);
                }
            }
        }

        // Pass both window and percent; downstream can choose which to use.
        return new Jitter($windowMs, $mode, $percent);
    }

}
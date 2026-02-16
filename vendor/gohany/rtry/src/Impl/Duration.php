<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

final class Duration
{
    /**
     * Formats milliseconds back into a compact duration token.
     * Chooses the largest whole unit: h, m, s, ms.
     */
    public static function formatMs(int $ms): string
    {
        if ($ms === 0) {
            return '0ms';
        }

        if ($ms < 1000) {
            return strval($ms);
        }

        // Default to ms; we'll replace if we find a shorter string.
        $best = $ms . 'ms';
        $bestLen = strlen($best);
        $bestRank = 0;

        // Tie-breaker preference when lengths are equal: h < m < s < ms
        $prefRank = [
            'h' => 3,
            'm' => 2,
            's' => 1,
            'ms' => 0,
        ];

        $units = [
            's' => 1000,
            'm' => 60000,
            'h' => 3600000,
        ];

        foreach ($units as $suffix => $unitMs) {
            $value = $ms / $unitMs;

            // Skip units that are < 1 of that unit (prevents ".03m", ".5h", etc.)
            if ($value < 1) {
                continue;
            }

            // Format with up to 2 decimals
            $str = number_format($value, 2, '.', '');
            $str = rtrim(rtrim($str, '0'), '.');

            // Skip units that would round to zero for a non-zero duration
//            if ($str === '0') {
//                continue;
//            }

            // Remove leading zero for 0 < value < 1 (e.g., ".5s")
            if ($value > 0 && $value < 1 && strpos($str, '0.') === 0) {
                $str = substr($str, 1);
            }

            $candidate = $str . $suffix;
            $len = strlen($candidate);
            $rank = $prefRank[$suffix];

            if ($len < $bestLen || ($len === $bestLen && $rank < $bestRank)) {
                $best = $candidate;
                $bestLen = $len;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    public static function parseDurationMs(string $token): int
    {
        $t = strtolower(trim($token));
        if ($t === '' || !preg_match('~^([0-9]+(?:\.[0-9]+)?)(ms|s|m|h)?$~i', $t, $m)) {
            throw new \InvalidArgumentException('Invalid duration: ' . $token);
        }

        $n = floatval($m[1]);
        $unit = $m[2] ?? 'ms';

        switch ($unit) {
            case 'ms': return (int) round($n, 0);
            case 's':  return (int) round($n * 1000, 0);
            case 'm':  return (int) round($n * 60000, 0);
            case 'h':  return (int) round($n * 3600000, 0);
        }

        throw new \InvalidArgumentException('Invalid duration unit in: ' . $token);

    }

    public static function formatPercent(float $p, int $maxPrecision = 3): string
    {
        $p = max(0.0, $p);
        $str = rtrim(rtrim(number_format($p, $maxPrecision, '.', ''), '0'), '.');
        return $str . '%';
    }

}
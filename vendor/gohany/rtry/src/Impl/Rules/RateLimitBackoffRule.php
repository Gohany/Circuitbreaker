<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Rules;

use Gohany\Rtry\Contracts\RuleInterface;
use Gohany\Rtry\Contracts\FailureMetadataInterface;
use Gohany\Rtry\Impl\FailureMetadata;
use Psr\Http\Message\ResponseInterface;

/**
 * Extracts backoff hints from HTTP response headers:
 * - Retry-After: seconds (delta) or HTTP-date (absolute)
 * - RateLimit-Reset / X-RateLimit-Reset: epoch seconds (absolute) OR small integer (delta)
 * - RateLimit-Reset-After / X-RateLimit-Reset-After: delta seconds
 *
 * Tags 'RATE_LIMITED' when a rate-limit header is observed.
 */
final class RateLimitBackoffRule implements RuleInterface
{
    /**
     * Extract backoff hints from common rate-limit headers.
     * Returns FailureMetadata with:
     *  - status (only if >= 400),
     *  - tags ['RATE_LIMITED'],
     *  - context patch (empty array here),
     *  - either minNextDelayMs OR notBeforeUnixMs,
     *  - echoed subset of headers that influenced the decision.
     */
    public function apply(\Throwable $e): ?FailureMetadataInterface
    {
        // 1) Find a PSR-7 response on the exception.
        $response = $this->extractResponse($e);
        if ($response === null) {
            return null;
        }

        // 2) Normalize headers to a simple lowercase map: name => firstValueString.
        $headers = $this->normalizeHeaders($response->getHeaders());
        if ($headers === []) {
            return null;
        }

        // 3) Compute backoff hints from the headers (Retry-After, RateLimit-Reset*, etc).
        $hints = $this->computeBackoffFromHeaders($headers);
        if ($hints === null) {
            return null; // No recognizable rate-limit hints.
        }
        $minNextDelayMs = $hints['minNextDelayMs'];
        $notBeforeMs = $hints['notBeforeUnixMs'];
        $usedKeys = $hints['usedKeys'];

        // 4) Decide whether to propagate HTTP status. (Only if >= 400.)
        $statusCode = $response->getStatusCode();
        $statusOut = ($statusCode >= 400) ? $statusCode : null;

        // 5) Build a slim header echo containing only keys that influenced the outcome.
        $headersOut = [];
        foreach ($usedKeys as $key) {
            if (isset($headers[$key])) {
                $headersOut[$key] = $headers[$key]; // already a single string
            }
        }

        // 6) Tag and return metadata.
        $tags = ['RATE_LIMITED'];
        $ctxPatch = []; // keep empty unless you want to surface more context

        return new FailureMetadata(
            $statusOut,
            $tags,
            $ctxPatch,
            $minNextDelayMs,
            $notBeforeMs,
            $headersOut
        );
    }

    /**
     * Try to get a PSR-7 response from a throwable via getResponse().
     */
    private function extractResponse(\Throwable $e): ?\Psr\Http\Message\ResponseInterface
    {
        if (!method_exists($e, 'getResponse')) {
            return null;
        }
        try {
            /** @var mixed $resp */
            $resp = $e->getResponse();

            return ($resp instanceof \Psr\Http\Message\ResponseInterface) ? $resp : null;
        } catch (\Throwable $_) {
            return null;
        }
    }

    /**
     * Normalize PSR-7 headers to: lowercased-name => first string value.
     * Examples:
     *   ['Retry-After' => ['1']]        -> ['retry-after' => '1']
     *   ['X-RateLimit-Reset' => [1234]] -> ['x-ratelimit-reset' => '1234']
     */
    private function normalizeHeaders(array $psrHeaders): array
    {
        $out = [];
        foreach ($psrHeaders as $name => $values) {
            $lower = strtolower((string)$name);
            if (is_array($values) && $values !== []) {
                $first = (string)reset($values);
            } else {
                $first = is_scalar($values) ? (string)$values : '';
            }
            if ($first !== '') {
                $out[$lower] = trim($first);
            }
        }

        return $out;
    }

    /**
     * Look for common rate-limit headers and compute either:
     *   - 'minNextDelayMs' (a delta to wait), or
     *   - 'notBeforeUnixMs' (absolute UNIX epoch ms when to retry),
     * plus which header keys were used (for echoing back).
     *
     * Recognized (case-insensitive, examples of values):
     *   - retry-after: "2" (seconds) OR "Wed, 21 Oct 2015 07:28:00 GMT" (HTTP-date)
     *   - ratelimit-reset-after / x-ratelimit-reset-after: "5" (delta seconds)
     *   - ratelimit-reset / x-ratelimit-reset: "1717000000" (epoch seconds) or "1717000000123" (epoch ms)
     *   - x-ratelimit-reset-ms: "1717000000123" (epoch ms)
     */
    private function computeBackoffFromHeaders(array $headers): ?array
    {
        // 1) Retry-After (delta seconds OR HTTP-date)
        if (isset($headers['retry-after'])) {
            $raw = $headers['retry-after'];
            // numeric => seconds
            if ($this->isNumericLike($raw)) {
                $sec = (float)$raw;
                $ms = (int)max(0, round($sec * 1000.0));

                return ['minNextDelayMs' => $ms, 'notBeforeUnixMs' => null, 'usedKeys' => ['retry-after']];
            }
            // HTTP-date => absolute epoch ms
            $ts = strtotime($raw);
            if ($ts !== false) {
                return ['minNextDelayMs' => null, 'notBeforeUnixMs' => $ts * 1000, 'usedKeys' => ['retry-after']];
            }
            // Unrecognized value — ignore and continue to other headers.
        }

        // 2) *-Reset-After (delta seconds)
        foreach (['ratelimit-reset-after', 'x-ratelimit-reset-after'] as $k) {
            if (isset($headers[$k]) && $this->isNumericLike($headers[$k])) {
                $sec = (float)$headers[$k];
                $ms = (int)max(0, round($sec * 1000.0));

                return ['minNextDelayMs' => $ms, 'notBeforeUnixMs' => null, 'usedKeys' => [$k]];
            }
        }

        // 3) *-Reset (absolute timestamp)
        foreach (['x-ratelimit-reset-ms'] as $k) { // explicit milliseconds
            if (isset($headers[$k]) && $this->isNumericLike($headers[$k])) {
                $ms = (int)$headers[$k];
                if ($ms > 0) {
                    return ['minNextDelayMs' => null, 'notBeforeUnixMs' => $ms, 'usedKeys' => [$k]];
                }
            }
        }
        foreach (['ratelimit-reset', 'x-ratelimit-reset'] as $k) { // seconds OR ms depending on magnitude
            if (isset($headers[$k]) && $this->isNumericLike($headers[$k])) {
                $num = (float)$headers[$k];
                if ($num <= 0) {
                    continue;
                }
                // Heuristic: <= 10^11 => seconds (epoch), >= 10^12 => ms.
                $ms = ($num >= 1e12) ? (int)$num : (int)round($num * 1000.0);

                return ['minNextDelayMs' => null, 'notBeforeUnixMs' => $ms, 'usedKeys' => [$k]];
            }
        }

        // Nothing recognized
        return null;
    }

    /** Numeric-like strings: "2", "2.5", "002". */
    private function isNumericLike(string $s): bool
    {
        // ctype_digit covers integers; fallback to is_numeric for floats
        return $s !== '' && (ctype_digit($s) || is_numeric($s));
    }
}
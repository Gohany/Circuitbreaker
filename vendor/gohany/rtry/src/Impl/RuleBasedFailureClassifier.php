<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Rtry\Contracts\FailureClassifierInterface;
use Gohany\Rtry\Contracts\FailureMetadataInterface;
use Gohany\Rtry\Contracts\RuleBasedClassifierInterface;
use Gohany\Rtry\Contracts\RuleInterface;

final class RuleBasedFailureClassifier implements FailureClassifierInterface, RuleBasedClassifierInterface
{
    /** @var array<int,RuleInterface> */
    private array $rules = [];


    /**
     * @param array<int,RuleInterface> $rules
     */
    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
    }

    public function classify(\Throwable $e): FailureMetadataInterface
    {
        $metas = $this->applyRules($e);

        $status = null;
        $tags = [];
        $ctxPatch = [];
        $minNextDelayMs = null;
        $notBeforeUnixMs = null;
        $headers = [];

        if ($metas !== null) {
            foreach ($metas as $m) {
                // Most recent non-null status code wins
                $s = $m->getStatusCode();
                if ($s !== null) {
                    $status = $s;
                }

                // Collect all tags (dedupe later)
                $tags = array_merge($tags, $m->getTags());

                // Later patches overwrite earlier ones
                $ctxPatch = array_replace($ctxPatch, $m->getContextPatch());

                // Respect the strictest constraints
                $min = $m->getMinNextDelayMs();
                if ($min !== null) {
                    $minNextDelayMs = $minNextDelayMs === null ? $min : max($minNextDelayMs, $min);
                }

                $nb = $m->getNotBeforeUnixMs();
                if ($nb !== null) {
                    $notBeforeUnixMs = $notBeforeUnixMs === null ? $nb : max($notBeforeUnixMs, $nb);
                }

                // Merge headers by name, append values, dedupe
                $headers = $this->mergeHeaders($headers, $m->getHeaders());
            }
        }

        // Always include tags derived from the Throwable
        $tags = array_values(array_unique(array_merge($tags, $this->collectTags($e))));

        // Fallbacks
        if ($status === null) {
            $status = $this->extractStatusCode($e);
        }

        return new FailureMetadata($status, $tags, $ctxPatch, $minNextDelayMs, $notBeforeUnixMs, $headers);
    }

    /**
     * @return array<int,FailureMetadataInterface>|null
     */
    private function applyRules(\Throwable $e): ?array
    {
        $out = [];
        foreach ($this->rules as $rule) {
            $result = $rule->apply($e);

            if ($result instanceof FailureMetadataInterface) {
                $out[] = $result;
                continue;
            }

            if (is_array($result)) {
                foreach ($result as $item) {
                    if ($item instanceof FailureMetadataInterface) {
                        $out[] = $item;
                    }
                }
            }
        }

        return !empty($out) ? $out : null;
    }

    public function addRule(RuleInterface $rule): self
    {
        $this->rules[] = $rule;
        return $this;
    }

    public function hasRuleOfType(string $fqcn): bool
    {
        foreach ($this->rules as $r) {
            if ($r instanceof $fqcn) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, array<int,string>> $base
     * @param array<string, array<int,string>> $add
     * @return array<string, array<int,string>>
     */
    private function mergeHeaders(array $base, array $add): array
    {
        foreach ($add as $name => $values) {
            $key = strtolower((string) $name);
            if (!isset($base[$key])) {
                $base[$key] = [];
            }
            foreach ((array) $values as $v) {
                $base[$key][] = $v;
            }
            // Dedupe while preserving order
            $base[$key] = array_values(array_unique($base[$key]));
        }
        return $base;
    }

    private function extractStatusCode(\Throwable $e): ?int
    {
        $code = (int) $e->getCode();

        if ($code >= 100 && $code <= 599) {
            return $code;
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function collectTags(\Throwable $e): array
    {
        $tags = [];
        $messageLower = strtolower((string) $e->getMessage());

        if ($this->containsAny($messageLower, ['timeout', 'timed out'])) {
            $tags[] = FailureToken::ETIMEDOUT;
        }

        if ($this->containsAny($messageLower, ['connection reset'])) {
            $tags[] = FailureToken::ECONNRESET;
        }

        if ($this->containsAny($messageLower, ['refused'])) {
            $tags[] = FailureToken::ECONNREFUSED;
        }

        if ($this->isNetworkError($e, $messageLower)) {
            $tags[] = FailureToken::NETWORK_ERROR;
        }

        if ($this->isDeadlock($e)) {
            $tags[] = FailureToken::DEADLOCK;
        }

        return $tags;
    }

    private function isNetworkError(\Throwable $e, string $messageLower): bool
    {
        if (interface_exists(\Psr\Http\Client\NetworkExceptionInterface::class)) {
            for ($t = $e; $t !== null; $t = $t->getPrevious()) {
                if ($t instanceof \Psr\Http\Client\NetworkExceptionInterface) {
                    return true;
                }
            }
        }

        return $this->containsAny($messageLower, [
            'network error',
            'network timeout',
            'network is unreachable',
            'host unreachable',
            'no route to host',
        ]);
    }

    private function isDeadlock(\Throwable $e): bool
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $tLower = strtolower((string) $t->getMessage());

            // Common textual indicators across databases
            if (
                $this->containsAny($tLower, [
                    'deadlock',                           // "deadlock detected/found/victim"
                    'lock wait timeout exceeded',         // MySQL InnoDB
                    'database is locked',                 // SQLite
                    'could not serialize access',         // PostgreSQL
                    'serialization failure',              // SQL standard / PostgreSQL
                ])
            ) {
                return true;
            }

            // Doctrine DBAL specific exception (if available)
            if (
                class_exists(\Doctrine\DBAL\Exception\DeadlockException::class)
                && $t instanceof \Doctrine\DBAL\Exception\DeadlockException
            ) {
                return true;
            }

            // PDOException: inspect SQLSTATE and vendor codes
            if ($t instanceof \PDOException) {
                $sqlState = $this->extractSqlState($t);
                if ($sqlState !== null && $this->isDeadlockSqlState($sqlState)) {
                    return true;
                }

                $vendorCode = $this->extractVendorCode($t);
                if ($vendorCode !== null && $this->isDeadlockVendorCode($vendorCode)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractSqlState(\PDOException $e): ?string
    {
        $errorInfo = $e->errorInfo ?? null;
        if (is_array($errorInfo) && isset($errorInfo[0]) && is_string($errorInfo[0]) && $errorInfo[0] !== '') {
            return strtoupper($errorInfo[0]);
        }

        $code = $e->getCode();
        if (is_string($code) && $code !== '') {
            return strtoupper($code);
        }

        return null;
    }

    private function isDeadlockSqlState(string $sqlState): bool
    {
        // Common SQLSTATEs for deadlocks/serialization failures
        // 40001: Serialization failure (PostgreSQL, SQL standard)
        // 40P01: Deadlock detected (PostgreSQL)
        return in_array($sqlState, ['40001', '40P01'], true);
    }

    private function extractVendorCode(\PDOException $e): ?int
    {
        $errorInfo = $e->errorInfo ?? null;
        if (is_array($errorInfo) && isset($errorInfo[1])) {
            $vendor = $errorInfo[1];

            if (is_int($vendor)) {
                return $vendor;
            }

            if (is_string($vendor) && ctype_digit($vendor)) {
                return (int) $vendor;
            }
        }

        return null;
    }

    private function isDeadlockVendorCode(int $vendorCode): bool
    {
        // MySQL / MariaDB / MSSQL common vendor codes:
        // 1213: ER_LOCK_DEADLOCK (MySQL)
        // 1205: Lock wait timeout exceeded / deadlock victim (MySQL/MSSQL)
        return in_array($vendorCode, [1213, 1205], true);
    }

    /**
     * @param array<int,string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
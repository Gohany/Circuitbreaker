<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Rtry\Contracts\FailureMetadataInterface;
use Gohany\Rtry\Contracts\RuleInterface;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use PDOException;
use PHPUnit\Framework\TestCase;

final class RuleBasedFailureClassifierTest extends TestCase
{

    public function test_merges_status_tags_context_delays_and_headers_from_rules_and_includes_message_tags(): void
    {
        // Arrange
        $c = new RuleBasedFailureClassifier();

        $m1 = new TestMeta(500,
            ['A', 'B'],
            ['x' => '1', 'y' => '1'],
            1000,
            1700000000000,
            ['Retry-After' => ['1'], 'X-Extra' => ['a']]
        );
        $m2 = new TestMeta(
    429, // later non-null should win
            ['B', 'C'],
            ['y' => '2', 'z' => '3'], // overwrite y
            2000, // max of min-delay
            1700000500000, // max not-before
            ['retry-after' => ['1', '2'], 'X-Extra' => ['b']]
        );

        $c->addRule(new TestRule($m1));
        $c->addRule(new TestRule($m2));

        // Exception message should add ETIMEDOUT and ECONNRESET tokens via collectTags()
        $e = new \RuntimeException('Operation timeout due to connection reset');

        // Act
        $meta = $c->classify($e);

        // Assert
        $this->assertSame(429, $meta->getStatusCode(), 'latest non-null status should win');

        $tags = $meta->getTags();
        sort($tags);
        // Expect union of rule tags plus derived tokens
        $this->assertContains('A', $tags);
        $this->assertContains('B', $tags);
        $this->assertContains('C', $tags);
        $this->assertContains('ETIMEDOUT', $tags);
        $this->assertContains('ECONNRESET', $tags);

        $this->assertSame(['x' => '1', 'y' => '2', 'z' => '3'],
            $meta->getContextPatch(),
            'later patches should overwrite');

        $this->assertSame(2000, $meta->getMinNextDelayMs(), 'minNextDelayMs should take the maximum across rules');
        $this->assertSame(1700000500000, $meta->getNotBeforeUnixMs(), 'notBefore should take the maximum across rules');

        $headers = $meta->getHeaders();
        $this->assertArrayHasKey(
            'retry-after',
            array_change_key_case($headers, CASE_LOWER),
            'headers merged to lowercase keys'
        );
        $this->assertArrayHasKey('x-extra', array_change_key_case($headers, CASE_LOWER));
        $lower = array_change_key_case($headers, CASE_LOWER);
        $this->assertSame(['1', '2'], $lower['retry-after'], 'values appended + deduped');
        $this->assertSame(['a', 'b'], $lower['x-extra']);
    }

    public function test_fallback_status_comes_from_throwable_code_when_no_rule_sets_status(): void
    {
        // Arrange: no rules, exception code in HTTP range
        $c = new RuleBasedFailureClassifier();
        $e = new \RuntimeException('network error', 502);

        // Act
        $meta = $c->classify($e);

        // Assert
        $this->assertSame(502, $meta->getStatusCode());
        $this->assertContains('NETWORK_ERROR', $meta->getTags(), 'message contains "network error"');
    }

    public function test_rule_returning_array_of_metas_is_supported_and_merged(): void
    {
        // Arrange
        $c = new RuleBasedFailureClassifier();
        $m1 = new TestMeta(null, ['T1'], ['a' => 1], 150, null, ['H1' => ['a']]);
        $m2 = new TestMeta(503, ['T2'], ['b' => 2], 300, 1700000200000, ['H1' => ['b']]);

        $c->addRule(new TestRule($m1));
        $c->addRule(new TestRule($m2));

        $e = new \RuntimeException('timeout'); // adds ETIMEDOUT

        // Act
        $meta = $c->classify($e);

        // Assert
        $this->assertSame(503, $meta->getStatusCode());
        $this->assertSame(300, $meta->getMinNextDelayMs());
        $this->assertSame(1700000200000, $meta->getNotBeforeUnixMs());

        $tags = $meta->getTags();
        $this->assertContains('T1', $tags);
        $this->assertContains('T2', $tags);
        $this->assertContains('ETIMEDOUT', $tags);

        $headers = array_change_key_case($meta->getHeaders(), CASE_LOWER);
        $this->assertSame(['a', 'b'], $headers['h1']);
    }

    public function test_collects_deadlock_tag_from_pdo_exception_sqlstate_and_vendor_code(): void
    {
        // Arrange: PDOException with SQLSTATE 40001 and vendor code 1213
        $e = new PDOException('deadlock detected');
        // errorInfo is a public property on PDOException: [SQLSTATE, driver_code, driver_message]
        $e->errorInfo = ['40001', 1213, 'deadlock'];

        $c = new RuleBasedFailureClassifier();

        // Act
        $meta = $c->classify($e);

        // Assert
        $this->assertContains('DEADLOCK', $meta->getTags());
    }
}

final class TestMeta implements FailureMetadataInterface
{
    private ?int $status;
    /** @var array<int,string> */
    private array $tags;
    /** @var array<string,mixed> */
    private array $ctx;
    private ?int $minDelay;
    private ?int $notBefore;
    /** @var array<string,array<int,string>> */
    private array $headers;

    /**
     * @param array<int,string> $tags
     * @param array<string,mixed> $ctx
     * @param array<string,array<int,string>> $headers
     */
    public function __construct(?int $status, array $tags, array $ctx, ?int $minDelay, ?int $notBefore, array $headers)
    {
        $this->status = $status;
        $this->tags = $tags;
        $this->ctx = $ctx;
        $this->minDelay = $minDelay;
        $this->notBefore = $notBefore;
        $this->headers = $headers;
    }

    public function getStatusCode(): ?int
    {
        return $this->status;
    }

    /** @return array<int,string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @return array<string,mixed> */
    public function getContextPatch(): array
    {
        return $this->ctx;
    }

    public function getMinNextDelayMs(): ?int
    {
        return $this->minDelay;
    }

    public function getNotBeforeUnixMs(): ?int
    {
        return $this->notBefore;
    }

    /** @return array<string,array<int,string>> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}

/**
 * Rule stub that always returns the configured result (single meta or array of metas).
 */
final class TestRule implements RuleInterface
{
    /** @var mixed */
    private $result;

    /** @param mixed $result */
    public function __construct($result)
    {
        $this->result = $result;
    }

    public function apply(\Throwable $e): ?FailureMetadataInterface
    {
        return $this->result;
    }
}
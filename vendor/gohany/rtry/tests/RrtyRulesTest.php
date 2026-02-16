<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Rtry\Contracts\FailureMetadataInterface;
use Gohany\Rtry\Impl\Rules\InstanceOfRule;
use Gohany\Rtry\Impl\Rules\MessageRegexRule;
use Gohany\Rtry\Impl\Rules\MethodStatusRule;
use Gohany\Rtry\Impl\Rules\RateLimitBackoffRule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * AAA tests for rule classes: InstanceOfRule, MessageRegexRule, MethodStatusRule, RateLimitBackoffRule.
 */
final class RrtyRulesTest extends TestCase
{

    public function test_instance_of_rule_matches_and_sets_status_and_tags(): void
    {
        // Arrange
        $rule = new InstanceOfRule(\RuntimeException::class, 503, ['TRANSIENT']);
        $e = new \RuntimeException('boom');

        // Act
        $m = $rule->apply($e);

        // Assert
        $this->assertInstanceOf(FailureMetadataInterface::class, $m);
        $this->assertSame(503, $m->getStatusCode());
        $this->assertSame(['TRANSIENT'], $m->getTags());
    }

    public function test_instance_of_rule_returns_null_on_non_match(): void
    {
        // Arrange
        $rule = new InstanceOfRule(\InvalidArgumentException::class, 400, ['BAD']);
        $e = new \RuntimeException('nope');

        // Act
        $m = $rule->apply($e);

        // Assert
        $this->assertNull($m);
    }

// ================== MessageRegexRule ==================

    public function test_message_regex_rule_matches_case_insensitive(): void
    {
        // Arrange
        $rule = new MessageRegexRule('/timeout/i', 504, ['GATEWAY_TIMEOUT']);
        $e = new \RuntimeException('Gateway Timeout while contacting upstream');

        // Act
        $m = $rule->apply($e);

        // Assert
        $this->assertInstanceOf(FailureMetadataInterface::class, $m);
        $this->assertSame(504, $m->getStatusCode());
        $this->assertSame(['GATEWAY_TIMEOUT'], $m->getTags());
    }

    public function test_message_regex_rule_returns_null_on_no_match_and_on_invalid_pattern(): void
    {
        // Arrange
        $ruleNoMatch = new MessageRegexRule('/notfound/', 404, ['NOT_FOUND']);
        $ruleBad = new MessageRegexRule('/(/', 500, ['BAD_REGEX']); // suppressed by @preg_match

        $e = new \RuntimeException('Something else');

        // Act
        $m1 = $ruleNoMatch->apply($e);
        $m2 = $ruleBad->apply($e);

        // Assert
        $this->assertNull($m1);
        $this->assertNull($m2);
    }

// ================== MethodStatusRule ==================

    public function test_method_status_rule_extracts_from_response_interface(): void
    {
        // Arrange
        $resp = $this->createMock(ResponseInterface::class);
        $resp->method('getStatusCode')->willReturn(503);
        $resp->method('getHeaders')->willReturn([]);

        $e = new TestHttpException('srv err', 0, $resp);

        $rule = new MethodStatusRule(['getResponse'], ['TRANSIENT']);

        // Act
        $m = $rule->apply($e);

        // Assert
        $this->assertInstanceOf(FailureMetadataInterface::class, $m);
        $this->assertSame(503, $m->getStatusCode());
        $this->assertSame(['TRANSIENT'], $m->getTags());
    }

    public function test_method_status_rule_extracts_from_numeric_method_like_getCode(): void
    {
        // Arrange
        $e = new TestCodeException('fail', 429);
        $rule = new MethodStatusRule(['getCode'], ['RETRY_AFTER']);

        // Act
        $m = $rule->apply($e);

        // Assert
        $this->assertInstanceOf(FailureMetadataInterface::class, $m);
        $this->assertSame(429, $m->getStatusCode());
        $this->assertSame(['RETRY_AFTER'], $m->getTags());
    }

    public function test_method_status_rule_returns_null_if_methods_missing_or_invalid(): void
    {
        // Arrange
        $e = new \RuntimeException('plain exception'); // no getResponse / getCode etc
        $rule = new MethodStatusRule(['getResponse', 'getCode'], ['T']);

        // Act
        $m = $rule->apply($e);

        // Assert
        $this->assertNull($m);
    }

// ================== RateLimitBackoffRule ==================

    public function test_rate_limit_rule_retry_after_seconds_sets_min_delay_and_tag_and_status(): void
    {
        // Arrange
        $resp = $this->createMock(ResponseInterface::class);
        $resp->method('getStatusCode')->willReturn(429);
        $resp->method('getHeaders')->willReturn([
            'Retry-After' => ['1'],  // 1 second delta
        ]);

        $e = new TestHttpException('ratelimited', 0, $resp);
        $rule = new RateLimitBackoffRule();

        // Act
        $m = $rule->apply($e);

        // Assert
        $this->assertInstanceOf(FailureMetadataInterface::class, $m);
        $this->assertSame(429, $m->getStatusCode(), 'status should be propagated when >= 400');
        $this->assertContains('RATE_LIMITED', $m->getTags(), 'should tag as RATE_LIMITED');
        $this->assertGreaterThanOrEqual(1000, (int)$m->getMinNextDelayMs());
        $this->assertNull($m->getNotBeforeUnixMs());
        $this->assertIsArray($m->getHeaders());
        $this->assertArrayHasKey('retry-after', array_change_key_case($m->getHeaders(), CASE_LOWER));
    }

    public function test_rate_limit_rule_retry_after_http_date_sets_not_before(): void
    {
        // Arrange
        $httpDate = 'Wed, 21 Oct 2015 07:28:00 GMT';
        $expectedMs = strtotime($httpDate) * 1000;

        $resp = $this->createMock(ResponseInterface::class);
        $resp->method('getStatusCode')->willReturn(503);
        $resp->method('getHeaders')->willReturn(['Retry-After' => [$httpDate]]);

        $e = new TestHttpException('retry later', 0, $resp);
        $rule = new RateLimitBackoffRule();

        // Act
        $m = $rule->apply($e);

        // Assert
        $this->assertInstanceOf(FailureMetadataInterface::class, $m);
        $this->assertSame(503, $m->getStatusCode());
        $this->assertSame($expectedMs, $m->getNotBeforeUnixMs());
        $this->assertContains('RATE_LIMITED', $m->getTags());
    }

    public function test_rate_limit_rule_status_below_400_returns_null_status_but_keeps_hints(): void
    {
        // Arrange
        $resp = $this->createMock(ResponseInterface::class);
        $resp->method('getStatusCode')->willReturn(200);
        $resp->method('getHeaders')->willReturn(['Retry-After' => ['2']]); // 2 seconds

        $e = new TestHttpException('info', 0, $resp);
        $rule = new RateLimitBackoffRule();

        // Act
        $m = $rule->apply($e);

        // Assert
        $this->assertInstanceOf(FailureMetadataInterface::class, $m);
        $this->assertNull($m->getStatusCode(), 'status should be null when < 400');
        $this->assertGreaterThanOrEqual(2000, (int)$m->getMinNextDelayMs());
        $this->assertContains('RATE_LIMITED', $m->getTags());
    }

    public function test_rate_limit_rule_returns_null_when_no_headers_or_response(): void
    {
        // Arrange
        $resp = $this->createMock(ResponseInterface::class);
        $resp->method('getStatusCode')->willReturn(500);
        $resp->method('getHeaders')->willReturn([]);

        $e = new TestHttpException('err', 0, $resp);
        $rule = new RateLimitBackoffRule();

        // Act
        $m1 = $rule->apply(new \RuntimeException('no getResponse'));
        $m2 = $rule->apply($e);

        // Assert
        $this->assertNull($m1, 'no getResponse method on throwable');
        $this->assertNull($m2, 'no rate-limit headers present');
    }
}

final class TestHttpException extends \RuntimeException
{
    private ?ResponseInterface $response;

    public function __construct(string $message = '', int $code = 0, ?ResponseInterface $response = null)
    {
        parent::__construct($message, $code);
        $this->response = $response;
    }

    public function getResponse()
    {
        if ($this->response === null) {
            throw new \RuntimeException('no response');
        }

        return $this->response;
    }
}

/** Exception exposing getCode() returning an HTTP-like status. */
final class TestCodeException extends \RuntimeException
{
}
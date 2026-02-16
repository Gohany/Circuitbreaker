<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Rules;

use Gohany\Rtry\Impl\FailureMetadata;
use Gohany\Rtry\Contracts\RuleInterface;

final class MessageRegexRule implements RuleInterface
{
    private string $pattern;

    private ?int $statusCode;

    /** @var array<int,string> */
    private array $tags;

    /**
     * @param array<int,string> $tags
     */
    public function __construct(string $pattern, ?int $statusCode = null, array $tags = [])
    {
        $this->pattern = $pattern;
        $this->statusCode = $statusCode;
        $this->tags = $tags;
    }

    public function apply(\Throwable $e): ?FailureMetadata
    {
        $msg = (string) $e->getMessage();

        if (@preg_match($this->pattern, $msg) === 1) {
            return new FailureMetadata($this->statusCode, $this->tags);
        }

        return null;
    }
}

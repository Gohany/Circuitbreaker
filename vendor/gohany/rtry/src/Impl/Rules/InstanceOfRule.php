<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Rules;

use Gohany\Rtry\Impl\FailureMetadata;
use Gohany\Rtry\Contracts\RuleInterface;

final class InstanceOfRule implements RuleInterface
{
    /** @var class-string */
    private string $class;

    private ?int $statusCode;

    /** @var array<int,string> */
    private array $tags;

    /**
     * @param class-string $class
     * @param array<int,string> $tags
     */
    public function __construct(string $class, ?int $statusCode = null, array $tags = [])
    {
        $this->class = $class;
        $this->statusCode = $statusCode;
        $this->tags = $tags;
    }

    public function apply(\Throwable $e): ?FailureMetadata
    {
        if ($e instanceof $this->class) {
            return new FailureMetadata($this->statusCode, $this->tags);
        }

        return null;
    }
}

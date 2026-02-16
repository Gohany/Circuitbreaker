<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl\Rules;

use Gohany\Rtry\Impl\FailureMetadata;
use Gohany\Rtry\Contracts\RuleInterface;
use Psr\Http\Message\ResponseInterface;

final class MethodStatusRule implements RuleInterface
{
    /** @var array<int,string> */
    private array $methods;

    /** @var array<int,string> */
    private array $tags;

    /**
     * @param array<int,string> $methods Methods to probe on the exception in order (e.g., 'getResponse', 'getCode').
     * @param array<int,string> $tags Tags to attach if a status can be extracted.
     */
    public function __construct(array $methods = ['getResponse', 'getCode'], array $tags = [])
    {
        $this->methods = $methods;
        $this->tags = $tags;
    }

    public function apply(\Throwable $e): ?FailureMetadata
    {
        foreach ($this->methods as $m) {
            if (!method_exists($e, $m)) {
                continue;
            }

            try {
                $val = $e->{$m}();

                // If response-like with getStatusCode()
                if ($val instanceof ResponseInterface) {
                    $code = (int) $val->getStatusCode();
                    return new FailureMetadata($code, $this->tags);
                }

                // If method returns an int that looks like an HTTP status
                if (is_int($val) && $val >= 100 && $val <= 599) {
                    return new FailureMetadata($val, $this->tags);
                }
            } catch (\Throwable $ignored) {
                // ignore and continue
            }
        }

        return null;
    }
}

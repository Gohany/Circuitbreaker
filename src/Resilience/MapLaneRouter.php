<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Resilience;

/**
 * Maps a `Context` attribute (e.g. `route` or `path`) to a bulkhead lane.
 *
 * Matching order:
 *  1) exact matches
 *  2) prefix matches
 *  3) regex matches
 *  4) default lane
 */
final class MapLaneRouter implements LaneRouterInterface
{
    private string $attribute;

    /** @var array<string,string> */
    private array $exact;

    /** @var array<string,string> */
    private array $prefix;

    /** @var array<string,string> regex => lane */
    private array $regex;

    private string $defaultLane;

    /**
     * @param array<string,string> $exact
     * @param array<string,string> $prefix
     * @param array<string,string> $regex
     */
    public function __construct(
        string $attribute,
        array $exact = [],
        array $prefix = [],
        array $regex = [],
        string $defaultLane = 'default'
    ) {
        $this->attribute = $attribute;
        $this->exact = $exact;
        $this->prefix = $prefix;
        $this->regex = $regex;
        $this->defaultLane = $defaultLane;
    }

    public function laneFor(Context $ctx): string
    {
        $raw = $ctx->get($this->attribute);
        $value = is_string($raw) ? $raw : '';
        if ($value === '') {
            return $ctx->getLane() !== '' ? $ctx->getLane() : $this->defaultLane;
        }

        if (array_key_exists($value, $this->exact)) {
            return $this->exact[$value];
        }

        foreach ($this->prefix as $pfx => $lane) {
            if ($pfx !== '' && strpos($value, $pfx) === 0) {
                return $lane;
            }
        }

        foreach ($this->regex as $pattern => $lane) {
            if ($pattern === '') {
                continue;
            }
            $ok = @preg_match($pattern, $value);
            if ($ok === 1) {
                return $lane;
            }
        }

        return $this->defaultLane;
    }
}

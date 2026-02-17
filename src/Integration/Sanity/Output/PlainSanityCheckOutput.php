<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Sanity\Output;

use Gohany\Circuitbreaker\SideEffect\SideEffectRequest;

final class PlainSanityCheckOutput implements SanityCheckOutputInterface
{
    /** @var resource */
    private $stream;

    /**
     * @param resource $stream
     */
    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    public function heading(string $title): void
    {
        $this->writeln($title);
    }

    public function info(string $message): void
    {
        $this->writeln($message);
    }

    public function step(string $title): void
    {
        $this->writeln("-> {$title}");
    }

    public function pass(string $message): void
    {
        $this->writeln("[PASS] {$message}");
    }

    public function fail(string $message): void
    {
        $this->writeln("[FAIL] {$message}");
    }

    public function sideEffect(SideEffectRequest $request): void
    {
        $line = json_encode([
            'domain' => $request->domain,
            'type' => $request->type,
            'payload' => $request->payload,
            'meta' => $request->meta,
        ], JSON_UNESCAPED_SLASHES);

        $this->writeln("SIDE_EFFECT {$line}");
    }

    private function writeln(string $line): void
    {
        fwrite($this->stream, $line . "\n");
    }
}

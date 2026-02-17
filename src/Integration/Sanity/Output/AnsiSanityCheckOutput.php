<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\Integration\Sanity\Output;

use Gohany\Circuitbreaker\SideEffect\SideEffectRequest;

final class AnsiSanityCheckOutput implements SanityCheckOutputInterface
{
    private const RESET = "\033[0m";
    private const GREEN = "\033[32m";
    private const RED = "\033[31m";
    private const YELLOW = "\033[33m";
    private const CYAN = "\033[36m";
    private const BOLD = "\033[1m";

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
        $this->writeln(self::BOLD . self::CYAN . $title . self::RESET);
    }

    public function info(string $message): void
    {
        $this->writeln(self::YELLOW . $message . self::RESET);
    }

    public function step(string $title): void
    {
        $this->writeln(self::CYAN . "→ " . $title . self::RESET);
    }

    public function pass(string $message): void
    {
        $this->writeln(self::GREEN . "✔ " . $message . self::RESET);
    }

    public function fail(string $message): void
    {
        $this->writeln(self::RED . "✘ " . $message . self::RESET);
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

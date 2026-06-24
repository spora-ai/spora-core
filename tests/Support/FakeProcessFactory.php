<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Stand-in for {@see \Symfony\Component\Process\Process} that records the
 * argv + cwd it was constructed with and returns canned stdout / stderr /
 * exit code. PluginManager only needs five methods on the return value of
 * its processFactory closure, so we duck-type them here.
 */
final class InMemoryProcess
{
    public function __construct(
        public readonly array $argv,
        public readonly string $cwd,
        private readonly string $output = '',
        private readonly string $errorOutput = '',
        private readonly int $exitCode = 0,
    ) {}

    public function run(): void {}

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}

/**
 * An invocable factory that records every (argv, cwd) pair the manager
 * passes through and returns a canned {@see InMemoryProcess} keyed by the
 * joined argv (or a default success process when no key matches).
 */
final class FakeProcessFactory
{
    /** @var list<array{argv: array<int, string>, cwd: string}> */
    public array $calls = [];

    /** @var array<string, InMemoryProcess> */
    private array $canned = [];

    /**
     * @param array<string, InMemoryProcess> $canned  Keyed by the joined argv string.
     */
    public function __construct(array $canned = [])
    {
        foreach ($canned as $key => $process) {
            $this->canned[$key] = $process;
        }
    }

    public function __invoke(array $argv, string $cwd): object
    {
        $this->calls[] = ['argv' => $argv, 'cwd' => $cwd];
        $key = implode(' ', $argv);
        return $this->canned[$key] ?? new InMemoryProcess($argv, $cwd);
    }
}

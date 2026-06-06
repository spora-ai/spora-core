<?php

declare(strict_types=1);

namespace Spora\Console;

/**
 * Bundle of per-loop flags and tunables so WorkerRunCommand::runLoopIteration()
 * stays under the 7-parameter cap (SonarQube S107).
 *
 * @internal Used only by WorkerRunCommand.
 */
final readonly class WorkerLoopOptions
{
    public function __construct(
        public bool $isDaemon,
        public bool $isOnce,
        public bool $includeQueue,
        public bool $useChildProcesses,
        public int $maxWorkers,
        public int $sleep,
        public int $staleMinutes,
    ) {}
}

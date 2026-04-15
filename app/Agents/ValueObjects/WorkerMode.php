<?php

declare(strict_types=1);

namespace Spora\Agents\ValueObjects;

enum WorkerMode: int
{
    /** Blocking in-process dispatch — default for dev/test. */
    case Sync = 1;

    /** HTTP returns QUEUED immediately; a persistent daemon polls for tasks. */
    case Worker = 0;
}

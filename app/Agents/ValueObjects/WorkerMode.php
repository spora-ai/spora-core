<?php

declare(strict_types=1);

namespace Spora\Agents\ValueObjects;

enum WorkerMode: string
{
    /** Blocking in-process dispatch — default for dev/test. */
    case Sync = 'sync';

    /** HTTP returns QUEUED immediately; cron drains tasks once per invocation. */
    case Cron = 'cron';

    /** HTTP returns QUEUED immediately; a persistent daemon polls for tasks. */
    case Worker = 'worker';
}

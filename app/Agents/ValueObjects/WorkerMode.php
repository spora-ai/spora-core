<?php

declare(strict_types=1);

namespace Spora\Agents\ValueObjects;

enum WorkerMode: int
{
    /** HTTP returns QUEUED immediately; a persistent driver (server or browser) ticks. */
    case Worker = 0;
}

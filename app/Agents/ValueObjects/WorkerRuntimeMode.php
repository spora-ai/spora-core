<?php

declare(strict_types=1);

namespace Spora\Agents\ValueObjects;

enum WorkerRuntimeMode: string
{
    case Server = 'server';  // bin/spora worker:run drains QUEUED
    case Client = 'client';  // SharedWorker drains QUEUED via /api/v1/tasks/{id}/tick
}

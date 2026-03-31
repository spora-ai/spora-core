<?php

declare(strict_types=1);

namespace Spora\Agents\Handlers;

use Spora\Agents\Messages\TickMessage;
use Spora\Agents\OrchestratorInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TickHandler
{
    public function __construct(
        private readonly OrchestratorInterface $orchestrator,
    ) {}

    public function __invoke(TickMessage $message): void
    {
        $this->orchestrator->tick($message->taskId);
    }
}

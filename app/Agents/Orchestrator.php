<?php

declare(strict_types=1);

namespace Spora\Agents;

use RuntimeException;
use Spora\Models\Task;

/**
 * TODO: Implement the full Orchestrator loop.
 * Drives Tasks through their lifecycle using the Symfony Messenger queue.
 */
final class Orchestrator implements OrchestratorInterface
{
    public function start(int $agentId, string $userPrompt, int $maxSteps = 10): Task
    {
        // TODO: Create Task record, dispatch first tick message
        throw new RuntimeException('Not implemented');
    }

    public function tick(int $taskId): void
    {
        // TODO: Load Task, call LLM, handle InputTool/OutputTool/completion
        throw new RuntimeException('Not implemented');
    }

    public function resume(int $taskId, array $approvedArguments): void
    {
        // TODO: Execute approved OutputTool call, re-dispatch tick
        throw new RuntimeException('Not implemented');
    }

    public function reject(int $taskId, string $reason): void
    {
        // TODO: Inject rejection message into history, re-dispatch tick
        throw new RuntimeException('Not implemented');
    }
}

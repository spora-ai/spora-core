<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Models\Task;

interface OrchestratorInterface
{
    /**
     * @param  int    $agentId
     * @param  string $userPrompt  The user's initial instruction.
     * @param  int    $maxSteps    Hard iteration cap. Copied to Task at creation.
     * @return Task                The newly created Task (status: RUNNING).
     */
    public function start(int $agentId, string $userPrompt, int $maxSteps = 10): Task;

    /**
     * One iteration of the loop. Called by the Symfony Messenger handler.
     */
    public function tick(int $taskId): void;

    /**
     * @param  int                  $taskId
     * @param  array<string, mixed> $approvedArguments  Arguments confirmed (or edited) by the human.
     */
    public function resume(int $taskId, array $approvedArguments): void;

    /**
     * @param  int    $taskId
     * @param  string $reason  Surfaced to the LLM so it can choose an alternative action.
     */
    public function reject(int $taskId, string $reason): void;
}

<?php

declare(strict_types=1);

namespace Spora\Agents;

use RuntimeException;
use Spora\Models\Task;

/**
 * Breaks the container bootstrap circular dependency:
 *   Orchestrator → MessageBus → TickHandler → Orchestrator
 *
 * The proxy is wired into the bus handler first. The real Orchestrator is
 * constructed with the bus, then injected via setInner(). All bus dispatches
 * that arrive after setInner() is called delegate to the real implementation.
 */
final class OrchestratorProxy implements OrchestratorInterface
{
    private ?OrchestratorInterface $inner = null;

    public function setInner(OrchestratorInterface $inner): void
    {
        $this->inner = $inner;
    }

    public function start(int $agentId, string $userPrompt, int $maxSteps = 10): Task
    {
        return $this->resolve()->start($agentId, $userPrompt, $maxSteps);
    }

    public function tick(int $taskId): void
    {
        $this->resolve()->tick($taskId);
    }

    public function resume(int $taskId, array $approvedArguments): void
    {
        $this->resolve()->resume($taskId, $approvedArguments);
    }

    public function reject(int $taskId, string $reason): void
    {
        $this->resolve()->reject($taskId, $reason);
    }

    private function resolve(): OrchestratorInterface
    {
        if ($this->inner === null) {
            throw new RuntimeException('OrchestratorProxy: inner orchestrator has not been set.');
        }

        return $this->inner;
    }
}

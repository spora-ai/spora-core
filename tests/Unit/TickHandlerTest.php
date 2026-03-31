<?php

declare(strict_types=1);

use Spora\Agents\Handlers\TickHandler;
use Spora\Agents\Messages\TickMessage;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Task;

test('__invoke() calls orchestrator->tick() with the message taskId', function (): void {
    $tickedIds = [];

    $orchestrator = new class ($tickedIds) implements OrchestratorInterface {
        public function __construct(private array &$tickedIds) {}

        public function tick(int $taskId): void
        {
            $this->tickedIds[] = $taskId;
        }

        public function start(int $agentId, string $userPrompt, int $maxSteps = 10): Task
        {
            return new Task();
        }

        public function resume(int $taskId, array $approvedArguments): void {}

        public function reject(int $taskId, string $reason): void {}
    };

    $handler = new TickHandler($orchestrator);
    $handler(new TickMessage(taskId: 17));

    expect($tickedIds)->toBe([17]);
});

test('__invoke() forwards the exact taskId from the message', function (): void {
    $received = null;

    $orchestrator = new class ($received) implements OrchestratorInterface {
        public function __construct(private mixed &$received) {}

        public function tick(int $taskId): void
        {
            $this->received = $taskId;
        }

        public function start(int $agentId, string $userPrompt, int $maxSteps = 10): Task
        {
            return new Task();
        }

        public function resume(int $taskId, array $approvedArguments): void {}

        public function reject(int $taskId, string $reason): void {}
    };

    $handler = new TickHandler($orchestrator);
    $handler(new TickMessage(taskId: 999));

    expect($received)->toBe(999);
});

<?php

declare(strict_types=1);

use Spora\Agents\OrchestratorInterface;
use Spora\Agents\OrchestratorProxy;
use Spora\Models\Task;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeInnerOrchestrator(): OrchestratorInterface
{
    return new class implements OrchestratorInterface {
        public array $calls = [];

        public function start(int $agentId, string $userPrompt, int $maxSteps = 10): Task
        {
            $this->calls[] = ['start', $agentId, $userPrompt, $maxSteps];
            return new Task();
        }

        public function tick(int $taskId): void
        {
            $this->calls[] = ['tick', $taskId];
        }

        public function resume(int $taskId, array $approvedArguments): void
        {
            $this->calls[] = ['resume', $taskId, $approvedArguments];
        }

        public function reject(int $taskId, string $reason): void
        {
            $this->calls[] = ['reject', $taskId, $reason];
        }
    };
}

// ---------------------------------------------------------------------------
// Tests: before setInner()
// ---------------------------------------------------------------------------

test('start() throws RuntimeException before setInner()', function (): void {
    $proxy = new OrchestratorProxy();
    expect(fn() => $proxy->start(1, 'hello'))->toThrow(RuntimeException::class);
});

test('tick() throws RuntimeException before setInner()', function (): void {
    $proxy = new OrchestratorProxy();
    expect(fn() => $proxy->tick(1))->toThrow(RuntimeException::class);
});

test('resume() throws RuntimeException before setInner()', function (): void {
    $proxy = new OrchestratorProxy();
    expect(fn() => $proxy->resume(1, []))->toThrow(RuntimeException::class);
});

test('reject() throws RuntimeException before setInner()', function (): void {
    $proxy = new OrchestratorProxy();
    expect(fn() => $proxy->reject(1, 'no'))->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// Tests: delegation after setInner()
// ---------------------------------------------------------------------------

test('tick() delegates to inner with correct taskId', function (): void {
    $proxy = new OrchestratorProxy();
    $inner = makeInnerOrchestrator();
    $proxy->setInner($inner);

    $proxy->tick(42);

    expect($inner->calls[0])->toBe(['tick', 42]);
});

test('start() delegates to inner with correct arguments', function (): void {
    $proxy = new OrchestratorProxy();
    $inner = makeInnerOrchestrator();
    $proxy->setInner($inner);

    $proxy->start(7, 'do something', 20);

    expect($inner->calls[0])->toBe(['start', 7, 'do something', 20]);
});

test('resume() delegates to inner with correct arguments', function (): void {
    $proxy = new OrchestratorProxy();
    $inner = makeInnerOrchestrator();
    $proxy->setInner($inner);

    $proxy->resume(5, ['arg' => 'value']);

    expect($inner->calls[0])->toBe(['resume', 5, ['arg' => 'value']]);
});

test('reject() delegates to inner with correct arguments', function (): void {
    $proxy = new OrchestratorProxy();
    $inner = makeInnerOrchestrator();
    $proxy->setInner($inner);

    $proxy->reject(3, 'not approved');

    expect($inner->calls[0])->toBe(['reject', 3, 'not approved']);
});

test('start() returns the Task from inner', function (): void {
    $proxy = new OrchestratorProxy();
    $inner = makeInnerOrchestrator();
    $proxy->setInner($inner);

    $result = $proxy->start(1, 'prompt');

    expect($result)->toBeInstanceOf(Task::class);
});

test('setInner() can be called multiple times and last one wins', function (): void {
    $proxy  = new OrchestratorProxy();
    $inner1 = makeInnerOrchestrator();
    $inner2 = makeInnerOrchestrator();

    $proxy->setInner($inner1);
    $proxy->setInner($inner2);
    $proxy->tick(99);

    expect($inner1->calls)->toBe([]);
    expect($inner2->calls[0])->toBe(['tick', 99]);
});

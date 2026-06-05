<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use InvalidArgumentException;
use Spora\Http\TaskController;
use Spora\Services\TaskServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stub TaskServiceInterface that returns canned data for controller tests.
 */
class StubTaskService implements TaskServiceInterface
{
    public ?int $startCalls = 0;
    public ?array $startResult = null;
    public bool $startShouldThrow = false;

    public function getTasksForUser(int $userId, ?int $agentId = null, ?string $since = null, ?int $page = null, ?int $perPage = null): array
    {
        return [
            'tasks' => [
                ['id' => 1, 'agent_id' => 10, 'status' => 'COMPLETED', 'user_prompt' => 'P', 'final_response' => 'R', 'step_count' => 1, 'max_steps' => 10, 'created_at' => null, 'updated_at' => null],
            ],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1],
        ];
    }

    public function startTask(int $userId, int $agentId, string $prompt, ?int $maxSteps = null, ?int $parentTaskId = null): array
    {
        $this->startCalls++;
        if ($this->startShouldThrow) {
            throw new InvalidArgumentException('Agent not found');
        }
        return $this->startResult ?? [
            'id' => 99,
            'agent_id' => $agentId,
            'status' => 'PENDING',
            'user_prompt' => $prompt,
            'final_response' => null,
            'step_count' => 0,
            'max_steps' => $maxSteps ?? 10,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function getTask(int $taskId, int $userId): ?array
    {
        return null;
    }

    public function getTaskWithHistory(int $taskId, int $userId, ?int $sinceSequence = null): ?array
    {
        if ($taskId === 999999) {
            return null;
        }
        return [
            'id' => $taskId,
            'agent_id' => 10,
            'status' => 'COMPLETED',
            'user_prompt' => 'p',
            'final_response' => 'r',
            'step_count' => 1,
            'max_steps' => 10,
            'created_at' => null,
            'updated_at' => null,
            'tool_calls' => [],
            'history' => [],
        ];
    }

    public function approveTask(int $taskId, int $userId, array $approvals): array
    {
        if ($taskId === 999999) {
            throw new InvalidArgumentException('Task not found.');
        }
        return [
            'id' => $taskId,
            'agent_id' => 10,
            'status' => 'APPROVED',
            'user_prompt' => 'p',
            'final_response' => null,
            'step_count' => 0,
            'max_steps' => 10,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function rejectTask(int $taskId, int $userId, string $reason): array
    {
        if ($taskId === 999999) {
            throw new InvalidArgumentException('Task not found.');
        }
        return [
            'id' => $taskId,
            'agent_id' => 10,
            'status' => 'REJECTED',
            'user_prompt' => 'p',
            'final_response' => null,
            'step_count' => 0,
            'max_steps' => 10,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function retryTask(int $taskId, int $userId): array
    {
        if ($taskId === 999999) {
            throw new InvalidArgumentException('Task not found.');
        }
        return [
            'id' => 100,
            'agent_id' => 10,
            'status' => 'PENDING',
            'user_prompt' => 'p',
            'final_response' => null,
            'step_count' => 0,
            'max_steps' => 10,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function continueTask(int $taskId, int $userId, string $prompt, ?int $additionalSteps = null): array
    {
        if ($taskId === 999999) {
            throw new InvalidArgumentException('Task not found.');
        }
        return [
            'id' => $taskId,
            'agent_id' => 10,
            'status' => 'PENDING',
            'user_prompt' => $prompt,
            'final_response' => null,
            'step_count' => 0,
            'max_steps' => 10,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function deleteTask(int $taskId, int $userId): bool
    {
        return $taskId !== 999999;
    }

    public function cancelRetryChain(int $taskId, int $userId): bool
    {
        if ($taskId === 999999) {
            throw new InvalidArgumentException('Task not found.');
        }
        return true;
    }
}

function makeTaskController(): array
{
    $authService = bootAuthLayer();
    $service = new StubTaskService();
    $controller = new TaskController($authService, $service);

    return [$controller, $authService, $service];
}

describe('TaskController::index', function (): void {
    test('returns 200 with paginated tasks', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $response = $controller->index(new Request());

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['tasks'])->toBeArray();
        expect($body['data']['meta']['total'])->toBe(1);
        expect($body['data'])->toHaveKey('server_time');
    });
});

describe('TaskController::show', function (): void {
    test('returns 200 with the task and history', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('taskId', 1);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['task']['id'])->toBe(1);
        expect($body['data']['task'])->toHaveKey('tool_calls');
        expect($body['data']['task'])->toHaveKey('history');
    });

    test('returns 404 for unknown id', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('taskId', 999999);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('TaskController::store', function (): void {
    test('returns 201 with the new task on success', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks', ['prompt' => 'Hello', 'agent_id' => 5]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = Request::create('/api/v1/tasks', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when prompt is empty', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks', ['prompt' => '', 'agent_id' => 5]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 when agent_id is missing', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks', ['prompt' => 'Hi']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 404 when service throws not-found', function (): void {
        [$controller, $authService, $service] = makeTaskController();
        bootAuth($authService);
        $service->startShouldThrow = true;

        $request = jsonRequest('POST', '/api/v1/tasks', ['prompt' => 'Hi', 'agent_id' => 5]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('TaskController::approve', function (): void {
    test('returns 200 on successful approval with batch payload', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks/1/approve', [
            'approvals' => [['provider_call_id' => 'abc', 'arguments' => []]],
        ]);
        $request->attributes->set('taskId', 1);
        $response = $controller->approve($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('returns 200 on successful approval with legacy single-tool payload', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks/1/approve', [
            'provider_call_id' => 'p1',
            'arguments' => ['foo' => 'bar'],
        ]);
        $request->attributes->set('taskId', 1);
        $response = $controller->approve($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = Request::create('/api/v1/tasks/1/approve', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $request->attributes->set('taskId', 1);
        $response = $controller->approve($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when provider_call_id is missing (legacy mode)', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks/1/approve', []);
        $request->attributes->set('taskId', 1);
        $response = $controller->approve($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 when an entry in batch lacks provider_call_id', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks/1/approve', [
            'approvals' => [['arguments' => []]],
        ]);
        $request->attributes->set('taskId', 1);
        $response = $controller->approve($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 404 for unknown id', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks/999999/approve', [
            'approvals' => [['provider_call_id' => 'x']],
        ]);
        $request->attributes->set('taskId', 999999);
        $response = $controller->approve($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('TaskController::reject', function (): void {
    test('returns 200 on successful rejection', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks/1/reject', ['reason' => 'nope']);
        $request->attributes->set('taskId', 1);
        $response = $controller->reject($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = Request::create('/api/v1/tasks/1/reject', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $request->attributes->set('taskId', 1);
        $response = $controller->reject($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 404 for unknown id', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks/999999/reject', ['reason' => 'x']);
        $request->attributes->set('taskId', 999999);
        $response = $controller->reject($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('TaskController::destroy', function (): void {
    test('returns 200 with deleted: true on success', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('taskId', 1);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['deleted'])->toBeTrue();
    });

    test('returns 404 for unknown id', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('taskId', 999999);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('TaskController::retry', function (): void {
    test('returns 201 on successful retry', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('taskId', 1);
        $response = $controller->retry($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
    });

    test('returns 404 for unknown id', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('taskId', 999999);
        $response = $controller->retry($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('TaskController::continue', function (): void {
    test('returns 200 on successful continue', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks/1/continue', ['prompt' => 'continue me']);
        $request->attributes->set('taskId', 1);
        $response = $controller->continue($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('returns 422 when prompt is missing', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks/1/continue', []);
        $request->attributes->set('taskId', 1);
        $response = $controller->continue($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 when additional_steps is out of range', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks/1/continue', [
            'prompt'           => 'go',
            'additional_steps' => 200,
        ]);
        $request->attributes->set('taskId', 1);
        $response = $controller->continue($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 404 for unknown id', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/tasks/999999/continue', ['prompt' => 'go']);
        $request->attributes->set('taskId', 999999);
        $response = $controller->continue($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('TaskController::cancelRetryChain', function (): void {
    test('returns 200 with deleted: true on success', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('taskId', 1);
        $response = $controller->cancelRetryChain($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('returns 404 for unknown id', function (): void {
        [$controller, $authService] = makeTaskController();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('taskId', 999999);
        $response = $controller->cancelRetryChain($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

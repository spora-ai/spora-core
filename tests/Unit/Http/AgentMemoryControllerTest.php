<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Spora\Auth\AuthService;
use Spora\Http\AgentMemoryController;
use Spora\Models\Agent;
use Spora\Services\MemoryService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

function makeAgentMemController(?AuthService $authService = null): array
{
    $authService = $authService ?? bootAuthLayer();
    $memoryService = new MemoryService();
    $controller = new AgentMemoryController($authService, $memoryService);

    return [$controller, $authService, $memoryService];
}

function createAgentMemUser(AuthService $authService, string $email): array
{
    static $seq = 0;
    $seq++;
    $unique = "{$seq}{$email}";
    $userId = $authService->register($unique, 'Password1!', 'User');
    simulateLoggedInSession($userId, $unique);

    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Test Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    return [$userId, (int) $agent->id];
}

describe('AgentMemoryController::index', function (): void {
    test('returns 200 with memories for an existing agent', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [$userId, $agentId] = createAgentMemUser($authService, 'index@example.com');
        $service->createAgentMemory($agentId, $userId, ['name' => 'M1', 'content' => 'x']);

        $request = new Request();
        $request->attributes->set('agentId', $agentId);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toHaveCount(1);
    });

    test('returns 404 for unknown agent', function (): void {
        [$controller, $authService] = makeAgentMemController();
        createAgentMemUser($authService, 'idx404@example.com');

        $request = new Request();
        $request->attributes->set('agentId', 999999);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('AgentMemoryController::store', function (): void {
    test('returns 201 with the created memory', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'store@example.com');

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories", ['name' => 'New', 'content' => 'c']);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'store400@example.com');

        $request = Request::create("/api/v1/agents/{$agentId}/memories", 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when name is empty', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'store422@example.com');

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories", ['name' => '']);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 404 when agent does not exist', function (): void {
        [$controller, $authService] = makeAgentMemController();
        createAgentMemUser($authService, 'store404@example.com');

        $request = jsonRequest('POST', '/api/v1/agents/999999/memories', ['name' => 'X']);
        $request->attributes->set('agentId', 999999);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('AgentMemoryController::show', function (): void {
    test('returns 200 with the memory', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [$userId, $agentId] = createAgentMemUser($authService, 'show@example.com');
        $created = $service->createAgentMemory($agentId, $userId, ['name' => 'M', 'content' => 'c']);

        $request = new Request();
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $created['memory']['id']);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('returns 404 for unknown memory', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'show404@example.com');

        $request = new Request();
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', 999999);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('AgentMemoryController::update', function (): void {
    test('returns 200 with the updated memory', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [$userId, $agentId] = createAgentMemUser($authService, 'update@example.com');
        $created = $service->createAgentMemory($agentId, $userId, ['name' => 'Old', 'content' => 'c']);

        $request = jsonRequest('PUT', "/api/v1/agents/{$agentId}/memories/{$created['memory']['id']}", ['name' => 'New', 'content' => 'c2']);
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $created['memory']['id']);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('returns 404 for unknown memory', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'update404@example.com');

        $request = jsonRequest('PUT', "/api/v1/agents/{$agentId}/memories/999999", ['name' => 'X']);
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', 999999);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'updatebad@example.com');

        $request = Request::create("/api/v1/agents/{$agentId}/memories/1", 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', 1);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });
});

describe('AgentMemoryController::destroy', function (): void {
    test('returns 200 with deleted: true on success', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [$userId, $agentId] = createAgentMemUser($authService, 'destroy@example.com');
        $created = $service->createAgentMemory($agentId, $userId, ['name' => 'X', 'content' => 'c']);

        $request = new Request();
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $created['memory']['id']);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['deleted'])->toBeTrue();
    });

    test('returns 404 for unknown memory', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'destroy404@example.com');

        $request = new Request();
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', 999999);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('AgentMemoryController::reorder', function (): void {
    test('returns 200 with success: true on valid order', function (): void {
        [$controller, $authService, $service] = makeAgentMemController();
        [$userId, $agentId] = createAgentMemUser($authService, 'reorder@example.com');
        $a = $service->createAgentMemory($agentId, $userId, ['name' => 'A', 'content' => 'a']);
        $b = $service->createAgentMemory($agentId, $userId, ['name' => 'B', 'content' => 'b']);

        $request = jsonRequest('PATCH', "/api/v1/agents/{$agentId}/memories/reorder", ['order' => [$b['memory']['id'], $a['memory']['id']]]);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'reorder400@example.com');

        $request = Request::create("/api/v1/agents/{$agentId}/memories/reorder", 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $request->attributes->set('agentId', $agentId);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when order is not an array', function (): void {
        [$controller, $authService] = makeAgentMemController();
        [, $agentId] = createAgentMemUser($authService, 'reorder422@example.com');

        $request = jsonRequest('PATCH', "/api/v1/agents/{$agentId}/memories/reorder", ['order' => 'oops']);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 404 when agent does not exist', function (): void {
        [$controller, $authService] = makeAgentMemController();
        createAgentMemUser($authService, 'reorder404@example.com');

        $request = jsonRequest('PATCH', '/api/v1/agents/999999/memories/reorder', ['order' => []]);
        $request->attributes->set('agentId', 999999);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

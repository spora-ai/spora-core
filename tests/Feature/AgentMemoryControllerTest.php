<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Http\AgentMemoryController;
use Spora\Models\Agent;
use Spora\Models\Memory;
use Spora\Services\MemoryService;
use Symfony\Component\HttpFoundation\Response;

function makeAgentMemoryController(?AuthService $authService = null): array
{
    $authService = $authService ?? bootAuthLayer();
    $memoryService = new MemoryService();
    $controller = new AgentMemoryController($authService, $memoryService);

    return [$controller, $authService, $memoryService];
}

function createMemoryTestUserWithAgents(AuthService $authService, string $email = 'agentcontroller@example.com'): array
{
    static $seq = 0;
    $seq++;
    $displayName = ucfirst(explode('@', "{$seq}{$email}")[0]);
    $userId = $authService->register("{$seq}{$email}", 'Password1!', $displayName);
    simulateLoggedInSession($userId, "{$seq}{$email}");

    $agentId1 = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Agent One',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ])->id;

    $agentId2 = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Agent Two',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ])->id;

    return [$userId, $agentId1, $agentId2];
}

// ---------------------------------------------------------------------------
// reorder
// ---------------------------------------------------------------------------

describe('AgentMemoryController::reorder', function (): void {

    test('reorder() returns 401 when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = jsonRequest('PATCH', '/api/v1/agents/1/memories/reorder', ['order' => []]);
        expect(fn() => $controller->reorder($request))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    test('reorder() returns 400 for invalid JSON', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        bootAuth($authService);

        $request = Symfony\Component\HttpFoundation\Request::create(
            '/api/v1/agents/1/memories/reorder',
            'PATCH',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'not json at all',
        );
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_JSON');
    });

    test('reorder() returns 422 when order is not an array', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        bootAuth($authService);

        $request = jsonRequest('PATCH', '/api/v1/agents/1/memories/reorder', ['order' => 'not-an-array']);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
        expect($body['error']['message'])->toContain('order must be an array');
    });

    test('reorder() returns 404 when agent does not exist', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        bootAuth($authService);

        $request = jsonRequest('PATCH', '/api/v1/agents/99999/memories/reorder', ['order' => []]);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('reorder() returns 200 with empty array', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [$userId, $agentId] = createMemoryTestUserWithAgents($authService);

        $request = jsonRequest('PATCH', "/api/v1/agents/{$agentId}/memories/reorder", ['order' => []]);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['success'])->toBeTrue();
    });

    test('reorder() reorders memories and persists to database', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [$userId, $agentId] = createMemoryTestUserWithAgents($authService);

        $m1 = Memory::create(['user_id' => $userId, 'agent_id' => $agentId, 'name' => 'first', 'order' => 1]);
        $m2 = Memory::create(['user_id' => $userId, 'agent_id' => $agentId, 'name' => 'second', 'order' => 2]);
        $m3 = Memory::create(['user_id' => $userId, 'agent_id' => $agentId, 'name' => 'third', 'order' => 3]);

        $request = jsonRequest('PATCH', "/api/v1/agents/{$agentId}/memories/reorder", [
            'order' => [$m3->id, $m1->id, $m2->id],
        ]);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);

        $m1->refresh();
        $m2->refresh();
        $m3->refresh();
        expect($m3->order)->toBe(1);
        expect($m1->order)->toBe(2);
        expect($m2->order)->toBe(3);
    });

    test('reorder() only affects the specified agent\'s memories', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [$userId, $agentId1, $agentId2] = createMemoryTestUserWithAgents($authService);

        $a1m1 = Memory::create(['user_id' => $userId, 'agent_id' => $agentId1, 'name' => 'a1_first', 'order' => 1]);
        $a1m2 = Memory::create(['user_id' => $userId, 'agent_id' => $agentId1, 'name' => 'a1_second', 'order' => 2]);
        $a2m1 = Memory::create(['user_id' => $userId, 'agent_id' => $agentId2, 'name' => 'a2_first', 'order' => 1]);

        // Reverse agent1's order
        $request = jsonRequest('PATCH', "/api/v1/agents/{$agentId1}/memories/reorder", [
            'order' => [$a1m2->id, $a1m1->id],
        ]);
        $request->attributes->set('agentId', $agentId1);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);

        // Agent 1's memories should be reordered
        $a1m1->refresh();
        $a1m2->refresh();
        expect($a1m2->order)->toBe(1);
        expect($a1m1->order)->toBe(2);

        // Agent 2's memory should be unaffected
        $a2m1->refresh();
        expect($a2m1->order)->toBe(1);
    });

    test('reorder() ignores memories from other agents in the order array', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [$userId, $agentId1, $agentId2] = createMemoryTestUserWithAgents($authService);

        $a1m = Memory::create(['user_id' => $userId, 'agent_id' => $agentId1, 'name' => 'a1', 'order' => 1]);
        $a2m = Memory::create(['user_id' => $userId, 'agent_id' => $agentId2, 'name' => 'a2', 'order' => 1]);

        // Try to pass agent2's memory ID in agent1's reorder
        $request = jsonRequest('PATCH', "/api/v1/agents/{$agentId1}/memories/reorder", [
            'order' => [$a2m->id, $a1m->id],
        ]);
        $request->attributes->set('agentId', $agentId1);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);

        // a1m should still be first (a2m ID was filtered out by service)
        $a1m->refresh();
        expect($a1m->order)->toBe(1);

        // a2m should be unaffected
        $a2m->refresh();
        expect($a2m->order)->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// index
// ---------------------------------------------------------------------------

describe('AgentMemoryController::index', function (): void {

    test('index() returns 401 when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', 1);
        expect(fn() => $controller->index($request))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    test('index() returns 404 when agent does not exist', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        bootAuth($authService);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', 99999);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('index() returns memories for an agent', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [$userId, $agentId] = createMemoryTestUserWithAgents($authService);

        Memory::create(['user_id' => $userId, 'agent_id' => $agentId, 'name' => 'agent_memory']);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $agentId);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toHaveCount(1);
        expect($body['data']['memories'][0]['name'])->toBe('agent_memory');
    });
});

// ---------------------------------------------------------------------------
// store
// ---------------------------------------------------------------------------

describe('AgentMemoryController::store', function (): void {

    test('store() returns 401 when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = jsonRequest('POST', '/api/v1/agents/1/memories', ['name' => 'test']);
        $request->attributes->set('agentId', 1);
        expect(fn() => $controller->store($request))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    test('store() returns 404 when agent does not exist', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/agents/99999/memories', ['name' => 'test']);
        $request->attributes->set('agentId', 99999);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('store() creates an agent memory and auto-assigns order', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [$userId, $agentId] = createMemoryTestUserWithAgents($authService);

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories", [
            'name'    => 'New Agent Memory',
            'content' => 'Agent-specific content',
        ]);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('New Agent Memory')
            ->and($body['data']['memory']['agent_id'])->toBe($agentId)
            ->and($body['data']['memory']['order'])->toBe(1);
    });

    test('store() returns 422 when name is empty', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [$userId, $agentId] = createMemoryTestUserWithAgents($authService);

        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/memories", ['name' => '']);
        $request->attributes->set('agentId', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });
});

// ---------------------------------------------------------------------------
// show
// ---------------------------------------------------------------------------

describe('AgentMemoryController::show', function (): void {

    test('show() returns 401 when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', 1);
        $request->attributes->set('memoryId', 1);
        expect(fn() => $controller->show($request))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    test('show() returns 404 when agent does not exist', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        bootAuth($authService);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', 99999);
        $request->attributes->set('memoryId', 1);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

// ---------------------------------------------------------------------------
// update
// ---------------------------------------------------------------------------

describe('AgentMemoryController::update', function (): void {

    test('update() returns 401 when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = jsonRequest('PUT', '/api/v1/agents/1/memories/1', ['name' => 'updated']);
        $request->attributes->set('agentId', 1);
        $request->attributes->set('memoryId', 1);
        expect(fn() => $controller->update($request))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    test('update() modifies an existing agent memory', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [$userId, $agentId] = createMemoryTestUserWithAgents($authService);

        $memory = Memory::create([
            'user_id'  => $userId,
            'agent_id' => $agentId,
            'name'     => 'original',
            'content'  => 'original content',
        ]);

        $request = jsonRequest('PUT', "/api/v1/agents/{$agentId}/memories/{$memory->id}", [
            'name'    => 'updated',
            'content' => 'new content',
        ]);
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $memory->id);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('updated')
            ->and($body['data']['memory']['content'])->toBe('new content');
    });
});

// ---------------------------------------------------------------------------
// destroy
// ---------------------------------------------------------------------------

describe('AgentMemoryController::destroy', function (): void {

    test('destroy() returns 401 when unauthenticated', function (): void {
        [$controller] = makeAgentMemoryController();
        clearSession();

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', 1);
        $request->attributes->set('memoryId', 1);
        expect(fn() => $controller->destroy($request))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    test('destroy() deletes an existing agent memory', function (): void {
        [$controller, $authService] = makeAgentMemoryController();
        [$userId, $agentId] = createMemoryTestUserWithAgents($authService);

        $memory = Memory::create([
            'user_id'  => $userId,
            'agent_id' => $agentId,
            'name'     => 'to_delete',
        ]);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('agentId', $agentId);
        $request->attributes->set('memoryId', $memory->id);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        expect(Memory::find($memory->id))->toBeNull();
    });
});

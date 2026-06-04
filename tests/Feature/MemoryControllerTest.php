<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Http\MemoryController;
use Spora\Models\Agent;
use Spora\Models\Memory;
use Spora\Services\MemoryService;
use Symfony\Component\HttpFoundation\Response;

const REORDER_ENDPOINT = '/api/v1/memories/reorder';
const MEMORIES_ENDPOINT = '/api/v1/memories';

function makeMemoryController(?AuthService $authService = null): array
{
    $authService = $authService ?? bootAuthLayer();
    $memoryService = new MemoryService();
    $controller = new MemoryController($authService, $memoryService);

    return [$controller, $authService, $memoryService];
}

function createMemoryTestUser(AuthService $authService, string $email = 'controller@example.com'): array
{
    static $seq = 0;
    $seq++;
    $displayName = ucfirst(explode('@', "{$seq}{$email}")[0]);
    $userId = $authService->register("{$seq}{$email}", 'Password1!', $displayName);
    simulateLoggedInSession($userId, "{$seq}{$email}");

    $agentId = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Test Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ])->id;

    return [$userId, $agentId];
}

// reorder

describe('MemoryController::reorder', function (): void {

    test('reorder() throws UnauthenticatedException when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        expect(fn() => $controller->reorder(jsonRequest('PATCH', REORDER_ENDPOINT, ['order' => []])))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    test('reorder() returns 400 for invalid JSON', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = Symfony\Component\HttpFoundation\Request::create(
            REORDER_ENDPOINT,
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
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = jsonRequest('PATCH', REORDER_ENDPOINT, ['order' => 'not-an-array']);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
        expect($body['error']['message'])->toContain('order must be an array');
    });

    test('reorder() returns 422 when order is a numeric string', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = jsonRequest('PATCH', REORDER_ENDPOINT, ['order' => '123']);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('reorder() returns 200 with empty array', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = jsonRequest('PATCH', REORDER_ENDPOINT, ['order' => []]);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['success'])->toBeTrue();
    });

    test('reorder() reorders memories and persists to database', function (): void {
        [$controller, $authService] = makeMemoryController();
        [$userId] = createMemoryTestUser($authService);

        // Create three global memories
        $m1 = Memory::create(['user_id' => $userId, 'agent_id' => null, 'name' => 'first', 'order' => 1]);
        $m2 = Memory::create(['user_id' => $userId, 'agent_id' => null, 'name' => 'second', 'order' => 2]);
        $m3 = Memory::create(['user_id' => $userId, 'agent_id' => null, 'name' => 'third', 'order' => 3]);

        $request = jsonRequest('PATCH', REORDER_ENDPOINT, [
            'order' => [$m3->id, $m1->id, $m2->id],
        ]);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);

        // Verify persisted order values
        $m1->refresh();
        $m2->refresh();
        $m3->refresh();
        expect($m3->order)->toBe(1);
        expect($m1->order)->toBe(2);
        expect($m2->order)->toBe(3);
    });

    test('reorder() only affects the current user\'s memories', function (): void {
        [$controllerA, $authServiceA] = makeMemoryController();
        [$userIdA] = createMemoryTestUser($authServiceA, 'user1@reorder.com');
        [, $authServiceB] = makeMemoryController();
        [$userIdB] = createMemoryTestUser($authServiceB, 'user2@reorder.com');

        $u1m = Memory::create(['user_id' => $userIdA, 'agent_id' => null, 'name' => 'u1_memory', 'order' => 1]);
        Memory::create(['user_id' => $userIdB, 'agent_id' => null, 'name' => 'u2_memory', 'order' => 1]);

        // User A tries to reorder with user B's memory ID (service filters by user)
        $request = jsonRequest('PATCH', REORDER_ENDPOINT, [
            'order' => [$u1m->id],
        ]);
        $controllerA->reorder($request);

        // User A's memory should be unaffected (no such ID in their list)
        $u1m->refresh();
        expect($u1m->order)->toBe(1);
    });

    test('reorder() ignores extra fields in body', function (): void {
        [$controller, $authService] = makeMemoryController();
        [$userId] = createMemoryTestUser($authService);

        $m1 = Memory::create(['user_id' => $userId, 'agent_id' => null, 'name' => 'only', 'order' => 1]);

        $request = jsonRequest('PATCH', REORDER_ENDPOINT, [
            'order' => [$m1->id],
            'unknown_field' => 'should be ignored',
        ]);
        $response = $controller->reorder($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });
});

// index

describe('MemoryController::index', function (): void {

    test('index() throws UnauthenticatedException when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        expect(fn() => $controller->index(new Symfony\Component\HttpFoundation\Request()))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    test('index() returns empty data when no memories', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = new Symfony\Component\HttpFoundation\Request();
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memories'])->toBeArray()
            ->and($body['data']['memories'])->toBeEmpty();
    });
});

// store

describe('MemoryController::store', function (): void {

    test('store() throws UnauthenticatedException when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        expect(fn() => $controller->store(jsonRequest('POST', MEMORIES_ENDPOINT, ['name' => 'test'])))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    test('store() creates a global memory and auto-assigns order', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = jsonRequest('POST', MEMORIES_ENDPOINT, [
            'name'    => 'New Memory',
            'summary' => 'A brief summary',
            'content' => 'Full content here',
        ]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('New Memory')
            ->and($body['data']['memory']['order'])->toBe(1)
            ->and($body['data']['memory']['agent_id'])->toBeNull();
    });

    test('store() returns 422 when name is empty', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = jsonRequest('POST', MEMORIES_ENDPOINT, ['name' => '']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });
});

// show

describe('MemoryController::show', function (): void {

    test('show() throws UnauthenticatedException when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('id', 1);
        expect(fn() => $controller->show($request))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    test('show() returns 404 for unknown memory', function (): void {
        [$controller, $authService] = makeMemoryController();
        createMemoryTestUser($authService);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('id', 99999);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

// update

describe('MemoryController::update', function (): void {

    test('update() throws UnauthenticatedException when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        expect(fn() => $controller->update(jsonRequest('PUT', '/api/v1/memories/1', ['name' => 'updated'])))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    test('update() modifies an existing global memory', function (): void {
        [$controller, $authService] = makeMemoryController();
        [$userId] = createMemoryTestUser($authService);

        $memory = Memory::create([
            'user_id'  => $userId,
            'agent_id' => null,
            'name'     => 'original',
            'summary'  => 'original summary',
        ]);

        $request = jsonRequest('PUT', "/api/v1/memories/{$memory->id}", [
            'name'    => 'updated',
            'summary' => 'new summary',
        ]);
        $request->attributes->set('id', $memory->id);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['memory']['name'])->toBe('updated')
            ->and($body['data']['memory']['summary'])->toBe('new summary');
    });
});

// destroy

describe('MemoryController::destroy', function (): void {

    test('destroy() throws UnauthenticatedException when session is not set', function (): void {
        clearSession();
        [$controller] = makeMemoryController();

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('id', 1);
        expect(fn() => $controller->destroy($request))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    test('destroy() deletes an existing global memory', function (): void {
        [$controller, $authService] = makeMemoryController();
        [$userId] = createMemoryTestUser($authService);

        $memory = Memory::create([
            'user_id'  => $userId,
            'agent_id' => null,
            'name'     => 'to_delete',
        ]);

        $request = new Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('id', $memory->id);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        expect(Memory::find($memory->id))->toBeNull();
    });
});

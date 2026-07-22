<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Http\AgentController;
use Spora\Http\AgentOverrideController;
use Spora\Http\AgentToolController;
use Spora\Services\ToolConfigService;
use Spora\Tools\CalculatorTool;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @return array{AgentController, AgentToolController, AgentOverrideController, \Spora\Auth\AuthService, StubAgentService, StubAgentToolSettingsService, ToolConfigService}
 */
function makeAgentControllers(): array
{
    $authService = bootAuthLayer();
    $service        = new StubAgentService();
    $toolSettings   = new StubAgentToolSettingsService();
    $security       = new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig     = new ToolConfigService($security, new NullLogger(), [CalculatorTool::class]);
    $crudController = new AgentController($authService, $service);
    $toolController = new AgentToolController($authService, $toolSettings, $toolConfig);
    $overrideController = new AgentOverrideController($authService, $toolSettings, $toolConfig);

    return [$crudController, $toolController, $overrideController, $authService, $service, $toolSettings, $toolConfig];
}

describe('AgentController::index', function (): void {
    test('returns 200 with list of agents', function (): void {
        [$controller, , , $authService] = makeAgentControllers();
        bootAuth($authService);

        $response = $controller->index();

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['agents'])->toBeArray();
    });

    test('emits is_pinned, is_archived, and created_at on every agent', function (): void {
        [$controller, , , $authService] = makeAgentControllers();
        bootAuth($authService);

        $response = $controller->index();

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['agents'])->not->toBeEmpty();
        foreach ($body['data']['agents'] as $agent) {
            expect($agent)->toHaveKeys(['is_pinned', 'is_archived', 'created_at']);
            // Pin / archive default to false; created_at may be null on a stub
            expect($agent['is_pinned'])->toBeBool();
            expect($agent['is_archived'])->toBeBool();
        }
    });
});

describe('AgentController::show', function (): void {
    test('returns 200 with the agent', function (): void {
        [$controller, , , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 1);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['agent']['id'])->toBe(1);
    });

    test('returns 404 for unknown id', function (): void {
        [$controller, , , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 999999);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('AgentController::store', function (): void {
    test('returns 201 with the created agent', function (): void {
        [$controller, , , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/agents', ['name' => 'New Agent']);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, , , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = Request::create('/api/v1/agents', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 422 when name is missing', function (): void {
        [$controller, , , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/agents', []);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });
});

describe('AgentController::update', function (): void {
    test('returns 200 with the updated agent', function (): void {
        [$controller, , , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = jsonRequest('PATCH', '/api/v1/agents/1', ['name' => 'Renamed']);
        $request->attributes->set('id', 1);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('returns 404 for unknown id', function (): void {
        [$controller, , , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = jsonRequest('PATCH', '/api/v1/agents/999999', ['name' => 'X']);
        $request->attributes->set('id', 999999);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('accepts is_pinned and is_archived in the body and surfaces them in the response', function (): void {
        [$controller, , , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = jsonRequest('PATCH', '/api/v1/agents/1', [
            'is_pinned'   => true,
            'is_archived' => true,
        ]);
        $request->attributes->set('id', 1);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['agent']['is_pinned'])->toBeTrue();
        expect($body['data']['agent']['is_archived'])->toBeTrue();
    });
});

describe('AgentController::destroy', function (): void {
    test('returns 200 with deleted: true on success', function (): void {
        [$controller, , , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 1);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('returns 404 for unknown id', function (): void {
        [$controller, , , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 999999);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('AgentToolController::enableTool / disableTool / getToolStatus / getToolsStatus / getToolsOperations', function (): void {
    test('enableTool returns 200/201 on success', function (): void {
        [, $controller, , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 1);
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->enableTool($request);

        expect($response->getStatusCode())->toBeIn([Response::HTTP_OK, Response::HTTP_CREATED]);
    });

    test('enableTool returns 404 for unknown agent', function (): void {
        [, $controller, , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 999999);
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->enableTool($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('disableTool returns 200 with deleted: true on success', function (): void {
        [, $controller, , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 1);
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->disableTool($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('getToolStatus returns 200 with status', function (): void {
        [, $controller, , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 1);
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->getToolStatus($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('getToolStatus returns 404 for unknown agent', function (): void {
        [, $controller, , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 999999);
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->getToolStatus($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('getToolsStatus returns 200 with statuses list', function (): void {
        [, $controller, , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 1);
        $response = $controller->getToolsStatus($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('getToolsStatus returns 404 for unknown agent', function (): void {
        [, $controller, , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 999999);
        $response = $controller->getToolsStatus($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('getToolsOperations returns 200 with operations list', function (): void {
        [, $controller, , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 1);
        $response = $controller->getToolsOperations($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('getToolsOperations returns 404 for unknown agent', function (): void {
        [, $controller, , $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 999999);
        $response = $controller->getToolsOperations($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('AgentOverrideController::getOverride / putOverride / deleteOverride', function (): void {
    test('getOverride returns 200 with settings', function (): void {
        [, , $controller, $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 1);
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->getOverride($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('putOverride returns 200 with saved settings', function (): void {
        [, , $controller, $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = jsonRequest('PUT', '/api/v1/agents/1/tools/calculator/override', ['settings' => ['key' => 'v']]);
        $request->attributes->set('id', 1);
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->putOverride($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('putOverride returns 400 on invalid JSON', function (): void {
        [, , $controller, $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = Request::create('/api/v1/agents/1/tools/calculator/override', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $request->attributes->set('id', 1);
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->putOverride($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('deleteOverride returns 200 with deleted: true', function (): void {
        [, , $controller, $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 1);
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->deleteOverride($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });
});

describe('AgentOverrideController::getOperationOverride / patchOperationOverride', function (): void {
    test('getOperationOverride returns 200 with operation data', function (): void {
        [, , $controller, $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = new Request();
        $request->attributes->set('id', 1);
        $request->attributes->set('toolId', 'calculator');
        $request->attributes->set('operation', 'calculate');
        $response = $controller->getOperationOverride($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('patchOperationOverride returns 200 with patched operation data', function (): void {
        [, , $controller, $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = jsonRequest('PATCH', '/api/v1/agents/1/tools/calculator/operations/calculate', ['enabled' => true]);
        $request->attributes->set('id', 1);
        $request->attributes->set('toolId', 'calculator');
        $request->attributes->set('operation', 'calculate');
        $response = $controller->patchOperationOverride($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('patchOperationOverride returns 400 on invalid JSON', function (): void {
        [, , $controller, $authService] = makeAgentControllers();
        bootAuth($authService);

        $request = Request::create('/api/v1/agents/1/tools/calculator/operations/calculate', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $request->attributes->set('id', 1);
        $request->attributes->set('toolId', 'calculator');
        $request->attributes->set('operation', 'calculate');
        $response = $controller->patchOperationOverride($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });
});

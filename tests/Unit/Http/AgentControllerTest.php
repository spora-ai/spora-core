<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Http\AgentController;
use Spora\Http\AgentOverrideController;
use Spora\Http\AgentToolController;
use Spora\Models\Agent;
use Spora\Services\AgentServiceInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\CalculatorTool;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stub AgentServiceInterface that returns canned data.
 */
class StubAgentService implements AgentServiceInterface
{
    public function getAgentsForUser(int $userId): array
    {
        return [[
            'id'                     => 1,
            'user_id'                => $userId,
            'name'                   => 'Stub Agent',
            'description'            => null,
            'recipe_id'              => null,
            'system_prompt'          => null,
            'llm_driver_config_id'   => null,
            'max_steps'              => 10,
            'is_active'              => true,
            'retry_after_minutes'    => 0,
            'max_retries'            => 0,
        ]];
    }

    public function createAgent(int $userId, array $data): Agent
    {
        $agent = new Agent();
        $agent->id = 99;
        $agent->user_id = $userId;
        $agent->name = $data['name'] ?? 'New';
        $agent->description = $data['description'] ?? null;
        $agent->recipe_id = null;
        $agent->system_prompt = $data['system_prompt'] ?? null;
        $agent->llm_driver_config_id = $data['llm_driver_config_id'] ?? null;
        $agent->max_steps = $data['max_steps'] ?? 10;
        $agent->is_active = true;
        $agent->retry_after_minutes = 0;
        $agent->max_retries = 0;

        return $agent;
    }

    public function getAgent(int $agentId, int $userId): ?Agent
    {
        if ($agentId === 999999) {
            return null;
        }
        $agent = new Agent();
        $agent->id = $agentId;
        $agent->user_id = $userId;
        $agent->name = 'Stub Agent';
        $agent->description = null;
        $agent->recipe_id = null;
        $agent->system_prompt = null;
        $agent->llm_driver_config_id = null;
        $agent->max_steps = 10;
        $agent->is_active = true;
        $agent->retry_after_minutes = 0;
        $agent->max_retries = 0;

        return $agent;
    }

    public function updateAgent(int $agentId, int $userId, array $data): ?Agent
    {
        return $this->getAgent($agentId, $userId);
    }

    public function deleteAgent(int $agentId, int $userId): bool
    {
        return $agentId !== 999999;
    }

    public function enableTool(int $agentId, int $userId, string $toolClass): array
    {
        if ($agentId === 999999) {
            return ['error' => 'Agent not found'];
        }
        return ['tool' => ['tool_class' => $toolClass, 'is_enabled' => true]];
    }

    public function disableTool(int $agentId, int $userId, string $toolClass): void
    {
        // no-op
    }

    public function getToolStatus(int $agentId, int $userId, string $toolClass): ?array
    {
        if ($agentId === 999999) {
            return null;
        }
        return ['tool_class' => $toolClass, 'is_enabled' => false, 'missing_required' => [], 'can_enable' => true];
    }

    public function getAllToolsStatus(int $agentId, int $userId): ?array
    {
        if ($agentId === 999999) {
            return null;
        }
        return [];
    }

    public function getOverride(int $agentId, int $userId, string $toolClass, bool $rawOnly = false): array
    {
        if ($agentId === 999999) {
            return [];
        }
        return ['key' => 'val'];
    }

    public function putOverride(int $agentId, int $userId, string $toolClass, array $settings): array
    {
        return $settings;
    }

    public function deleteOverride(int $agentId, int $userId, string $toolClass): void
    {
        // no-op
    }

    public function getToolsOperations(int $agentId, int $userId): ?array
    {
        if ($agentId === 999999) {
            return null;
        }
        return [];
    }

    public function getOperationOverride(int $agentId, int $userId, string $toolClass, string $operation): array
    {
        return [];
    }

    public function patchOperationOverride(int $agentId, int $userId, string $toolClass, string $operation, array $data): array
    {
        return ['operation' => $operation, 'tool_class' => $toolClass] + $data;
    }
}

/**
 * @return array{AgentController, AgentToolController, AgentOverrideController, \Spora\Auth\AuthService, StubAgentService, ToolConfigService}
 */
function makeAgentControllers(): array
{
    $authService = bootAuthLayer();
    $service = new StubAgentService();
    $security = new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig = new ToolConfigService($security, new NullLogger(), [CalculatorTool::class]);
    $crudController = new AgentController($authService, $service, $toolConfig);
    $toolController = new AgentToolController($authService, $service, $toolConfig);
    $overrideController = new AgentOverrideController($authService, $service, $toolConfig);

    return [$crudController, $toolController, $overrideController, $authService, $service, $toolConfig];
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

<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use SensitiveParameter;
use Spora\Auth\AuthService;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Services\AgentServiceInterface;
use Spora\Services\ToolConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AgentController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly AgentServiceInterface $agentService,
        private readonly ToolConfigService $toolConfigService,
    ) {}

    /**
     * GET /api/v1/agents
     */
    public function index(#[SensitiveParameter] Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        $agents = $this->agentService->getAgentsForUser($userId);

        return new JsonResponse(['data' => ['agents' => $agents]]);
    }

    /**
     * POST /api/v1/agents
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->error('VALIDATION_ERROR', 'name is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = [
            'name'          => $name,
            'description'   => trim((string) ($body['description'] ?? '')) ?: null,
            'system_prompt' => trim((string) ($body['system_prompt'] ?? '')) ?: null,
            'llm_driver_config_id' => isset($body['llm_driver_config_id']) ? (int) $body['llm_driver_config_id'] : null,
            'max_steps'     => (int) ($body['max_steps'] ?? 10),
        ];

        $agent = $this->agentService->createAgent($userId, $data);

        return new JsonResponse(
            ['data' => ['agent' => $this->agentResource($agent)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * GET /api/v1/agents/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $agent = $this->agentService->getAgent($agentId, $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['agent' => $this->agentResource($agent)]]);
    }

    /**
     * PATCH /api/v1/agents/{id}
     */
    public function update(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $allowed = ['name', 'description', 'system_prompt', 'llm_driver_config_id', 'max_steps', 'retry_after_minutes', 'max_retries'];
        $data = array_intersect_key($body, array_flip($allowed));

        $agent = $this->agentService->updateAgent($agentId, $userId, $data);

        if ($agent === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['agent' => $this->agentResource($agent)]]);
    }

    /**
     * DELETE /api/v1/agents/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $deleted = $this->agentService->deleteAgent($agentId, $userId);

        if (!$deleted) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * POST /api/v1/agents/{id}/tools/{toolClass}/enable
     */
    public function enableTool(Request $request): JsonResponse
    {
        $userId    = $this->authService->currentUserId();
        $agentId   = (int) $request->attributes->get('id', 0);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($toolClass === null) {
            return $this->error('VALIDATION_ERROR', 'toolClass is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->agentService->enableTool($agentId, $userId, $toolClass);

        if (array_key_exists('error', $result)) {
            return $this->notFound();
        }

        $isIdempotent = array_key_exists('is_idempotent', $result);
        $status = $isIdempotent ? Response::HTTP_OK : Response::HTTP_CREATED;
        if ($isIdempotent) {
            unset($result['is_idempotent']);
        }
        return new JsonResponse(['data' => $result], $status);
    }

    /**
     * PATCH /api/v1/agents/{id}/tools/{toolClass}
     */
    public function patchTool(Request $request): JsonResponse
    {
        $userId    = $this->authService->currentUserId();
        $agentId   = (int) $request->attributes->get('id', 0);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($toolClass === null) {
            return $this->notFound();
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $tool = $this->agentService->patchTool($agentId, $userId, $toolClass, $body);

        if ($tool === null) {
            return $this->error('NOT_FOUND', 'Tool is not enabled for this agent.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['tool' => $this->toolResource($tool)]]);
    }

    /**
     * GET /api/v1/agents/{id}/tools/{toolId}/status
     */
    public function getToolStatus(Request $request): JsonResponse
    {
        $userId   = $this->authService->currentUserId();
        $agentId  = (int) $request->attributes->get('id', 0);
        $toolId   = (string) $request->attributes->get('toolId', '');
        $toolClass = $this->toolConfigService->resolveToolClass($toolId);

        if ($toolClass === null) {
            return $this->notFound();
        }

        $status = $this->agentService->getToolStatus($agentId, $userId, $toolClass);

        if ($status === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $status]);
    }

    /**
     * GET /api/v1/agents/{id}/tools/status
     */
    public function getToolsStatus(Request $request): JsonResponse
    {
        $userId  = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $statuses = $this->agentService->getAllToolsStatus($agentId, $userId);

        if ($statuses === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['statuses' => $statuses]]);
    }

    /**
     * GET /api/v1/agents/{id}/tools/operations
     */
    public function getToolsOperations(Request $request): JsonResponse
    {
        $userId  = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $operations = $this->agentService->getToolsOperations($agentId, $userId);

        if ($operations === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['operations' => $operations]]);
    }

    /**
     * DELETE /api/v1/agents/{id}/tools/{toolClass}/enable
     */
    public function disableTool(Request $request): JsonResponse
    {
        $userId    = $this->authService->currentUserId();
        $agentId   = (int) $request->attributes->get('id', 0);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($toolClass === null) {
            return $this->notFound();
        }

        $this->agentService->disableTool($agentId, $userId, $toolClass);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * GET /api/v1/agents/{id}/tools/{toolClass}/override
     */
    public function getOverride(Request $request): JsonResponse
    {
        $userId   = $this->authService->currentUserId();
        $agentId  = (int) $request->attributes->get('id', 0);
        $toolId   = (string) $request->attributes->get('toolId', '');
        $rawOnly  = $request->query->get('raw') === 'true';

        $toolClass = $toolId === 'llm_configuration'
            ? 'llm_configuration'
            : $this->toolConfigService->resolveToolClass($toolId);

        if ($toolClass === null) {
            return $this->notFound();
        }

        $settings = $this->agentService->getOverride($agentId, $userId, $toolClass, $rawOnly);

        return new JsonResponse(['data' => ['settings' => $settings]]);
    }

    /**
     * PUT /api/v1/agents/{id}/tools/{toolId}/override
     */
    public function putOverride(Request $request): JsonResponse
    {
        $userId    = $this->authService->currentUserId();
        $agentId   = (int) $request->attributes->get('id', 0);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($toolClass === null) {
            return $this->notFound();
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $settings = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : $body;

        $masked = $this->agentService->putOverride($agentId, $userId, $toolClass, $settings);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    /**
     * DELETE /api/v1/agents/{id}/tools/{toolId}/override
     */
    public function deleteOverride(Request $request): JsonResponse
    {
        $userId    = $this->authService->currentUserId();
        $agentId   = (int) $request->attributes->get('id', 0);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($toolClass === null) {
            return $this->notFound();
        }

        $this->agentService->deleteOverride($agentId, $userId, $toolClass);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * GET /api/v1/agents/{id}/tools/{toolClass}/operations/{operation}
     */
    public function getOperationOverride(Request $request): JsonResponse
    {
        $userId     = $this->authService->currentUserId();
        $agentId    = (int) $request->attributes->get('id', 0);
        $toolClass  = $this->resolveToolClassFromRequest($request);
        $operation  = (string) $request->attributes->get('operation', '');

        if ($toolClass === null || $operation === '') {
            return $this->notFound();
        }

        $result = $this->agentService->getOperationOverride($agentId, $userId, $toolClass, $operation);

        return new JsonResponse(['data' => $result]);
    }

    /**
     * PATCH /api/v1/agents/{id}/tools/{toolClass}/operations/{operation}
     */
    public function patchOperationOverride(Request $request): JsonResponse
    {
        $userId     = $this->authService->currentUserId();
        $agentId    = (int) $request->attributes->get('id', 0);
        $toolClass  = $this->resolveToolClassFromRequest($request);
        $operation  = (string) $request->attributes->get('operation', '');

        if ($toolClass === null || $operation === '') {
            return $this->notFound();
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->agentService->patchOperationOverride($agentId, $userId, $toolClass, $operation, $body);

        return new JsonResponse(['data' => $result]);
    }


    private function resolveToolClassFromRequest(Request $request): ?string
    {
        $toolId = (string) $request->attributes->get('toolId', '');

        if ($toolId === '') {
            return null;
        }

        return $this->toolConfigService->resolveToolClass($toolId);
    }

    /**
     * @return array<string, mixed>
     */
    private function agentResource(Agent $agent): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int,AgentTool> $tools */
        $tools = $agent->agentTools;

        return [
            'id'                   => (int) $agent->id,
            'name'                 => $agent->name,
            'description'          => $agent->description,
            'recipe_id'            => $agent->recipe_id,
            'system_prompt'        => $agent->system_prompt,
            'llm_driver_config_id' => $agent->llm_driver_config_id,
            'max_steps'            => (int) $agent->max_steps,
            'is_active'            => (bool) $agent->is_active,
            'retry_after_minutes'  => (int) ($agent->retry_after_minutes ?? 0),
            'max_retries'          => (int) ($agent->max_retries ?? 0),
            'tools'                => $tools->map(fn(AgentTool $t) => $this->toolResource($t))->values()->toArray(),
        ];
    }

    private function toolResource(AgentTool $tool): array
    {
        $raw = $tool->getRawOriginal('auto_approve');

        return [
            'tool_class'   => $tool->tool_class,
            'tool_name'    => $tool->tool_name,
            'auto_approve' => $raw === null ? null : (bool) $raw,
        ];
    }

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Agent not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}

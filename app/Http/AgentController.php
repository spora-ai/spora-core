<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Services\AgentServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agent CRUD endpoints.
 *
 * Tool enablement / status / overrides are handled by AgentToolController
 * and AgentOverrideController respectively.
 */
final class AgentController
{
    use AgentControllerJsonHelpers;

    private const MSG_INVALID_JSON = 'Request body must be valid JSON.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly AgentServiceInterface $agentService,
    ) {}

    /**
     * GET /api/v1/agents
     */
    public function index(): JsonResponse
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
            return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
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
            return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
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
            'tools' => $tools->map(static fn(AgentTool $t) => [
                'tool_class' => $t->tool_class,
                'tool_name'  => $t->tool_name,
            ])->values()->toArray(),
        ];
    }
}

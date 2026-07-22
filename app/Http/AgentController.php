<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Drivers\DriverFactory;
use Spora\Models\Agent;
use Spora\Services\AgentResource;
use Spora\Services\AgentServiceInterface;
use Spora\Services\ToolIconResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Agent CRUD endpoints.
 *
 * Tool enablement / status / overrides are handled by AgentToolController
 * and AgentOverrideController respectively.
 */
final class AgentController
{
    use JsonControllerHelpers;

    private const MSG_AGENT_NOT_FOUND = 'Agent not found.';
    private const MSG_INVALID_JSON = 'Request body must be valid JSON.';

    /**
     * Columns the multi-select picker (ToolSettingField) is allowed to
     * request via `?select=…`. Anything outside this list is silently
     * dropped so we don't widen the API surface when the schema grows.
     */
    private const SELECTABLE_COLUMNS = ['id', 'name'];

    public function __construct(
        private readonly AuthService $authService,
        private readonly AgentServiceInterface $agentService,
        private readonly ?DriverFactory $driverFactory = null,
        private readonly ?ToolIconResolver $toolIconResolver = null,
    ) {}

    /**
     * GET /api/v1/agents
     *
     * Optional `?select=id,name` query param projects to a subset of columns
     * (used by the multi-select ToolSetting field to fetch the agent list
     * without serializing the full payload). Columns are allowlisted so
     * clients can't request internal fields like `system_prompt`. Backward-
     * compatible: no `?select` returns the full payload via AgentService.
     */
    public function index(?Request $request = null): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        $select = $request?->query->get('select');
        if (is_string($select) && $select !== '') {
            $requested = array_values(array_filter(
                array_map('trim', explode(',', $select)),
                static fn(string $c): bool => $c !== '',
            ));
            // Only safe-for-picker columns are exposed via ?select. Anything
            // else is silently dropped so future schema additions don't leak.
            $columns = array_values(array_intersect($requested, self::SELECTABLE_COLUMNS));
            if ($columns !== []) {
                $agents = Agent::where('user_id', $userId)
                    ->orderBy('name')
                    ->get($columns)
                    ->all();
                return new JsonResponse(['data' => ['agents' => $agents]]);
            }
        }

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
            // `notes` accepted on POST so an operator can seed runbook text
            // on first-create, not just PATCH-edit. Mirrors the PATCH path.
            'notes'         => isset($body['notes']) && is_string($body['notes']) && $body['notes'] !== ''
                ? $body['notes']
                : null,
            'llm_driver_config_id' => isset($body['llm_driver_config_id']) ? (int) $body['llm_driver_config_id'] : null,
            'max_steps'     => (int) ($body['max_steps'] ?? 10),
            'allow_followup' => array_key_exists('allow_followup', $body) ? (bool) $body['allow_followup'] : true,
        ];

        $agent = $this->agentService->createAgent($userId, $data);

        return new JsonResponse(
            ['data' => ['agent' => AgentResource::toArray($agent, $this->resolveSupportsImageInput($agent), $this->toolIconResolver)]],
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
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['agent' => AgentResource::toArray($agent, $this->resolveSupportsImageInput($agent), $this->toolIconResolver)]]);
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

        $allowed = ['name', 'description', 'system_prompt', 'notes', 'llm_driver_config_id', 'max_steps', 'allow_followup', 'retry_after_minutes', 'max_retries', 'is_pinned', 'is_archived', 'is_favorite'];
        $data = array_intersect_key($body, array_flip($allowed));

        // Booleans arrive as either real bools or boolean-strings (the form
        // layer + curl both send 'true'/'false'). Coerce via FILTER_VALIDATE_BOOLEAN
        // so the service receives a real bool regardless of transport.
        // notes stays a raw string (markdown) — never coerced.
        foreach (['is_pinned', 'is_archived', 'is_favorite'] as $boolKey) {
            if (array_key_exists($boolKey, $data)) {
                $data[$boolKey] = filter_var($data[$boolKey], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $agent = $this->agentService->updateAgent($agentId, $userId, $data);

        if ($agent === null) {
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['agent' => AgentResource::toArray($agent, $this->resolveSupportsImageInput($agent), $this->toolIconResolver)]]);
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
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    private function resolveSupportsImageInput(Agent $agent): bool
    {
        if ($this->driverFactory === null) {
            return false;
        }
        try {
            $driver = $this->driverFactory->makeFromAgent($agent);
        } catch (Throwable) {
            return false;
        }
        return $driver->supportsImageInput();
    }
}

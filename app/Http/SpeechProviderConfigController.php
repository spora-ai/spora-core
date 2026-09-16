<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Http\Exceptions\SpeechProviderConfigException;
use Spora\Services\AgentServiceInterface;
use Spora\Services\SpeechProviderConfigService;
use Spora\Speech\SpeechToTextRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API for speech-to-text provider configurations.
 *
 * Mirrors {@see LLMConfigController} — same endpoints, same wire
 * shapes, same auth gates:
 *
 *   GET    /api/v1/speech/provider-configs/schema
 *                                              — registered STT classes + settings_schema
 *                                                (drives the dynamic create-config form)
 *   GET    /api/v1/speech/provider-configs?group_id=N
 *                                              — list (admin: globals; user: own overrides;
 *                                                with group_id: that group's configs)
 *   POST   /api/v1/speech/provider-configs          — create a config
 *   PUT    /api/v1/speech/provider-configs/{id}     — update a config
 *   DELETE /api/v1/speech/provider-configs/{id}     — delete a config
 *   POST   /api/v1/speech/provider-configs/{id}/set-default
 *                                              — mark one global config as default
 *                                                (admin-only; transaction + lockForUpdate)
 *
 * The /api/v1/speech/preference endpoints live on
 * {@see SpeechPreferenceController} so the provider-config controller
 * stays under the SonarCloud S1448 20-method ceiling and the
 * preference surface mirrors the LLM split
 * (LLMConfigController + UserPreferenceController + GroupPreferencesController).
 *
 * Auth model:
 *   - Global mutations require admin (AuthService::isAdmin).
 *   - User-scope writes require principal_id = caller user-principal.
 *   - Group-scope writes require GroupService::callerCanManage(groupId, userId, isAdmin).
 *   - Reads are existence-hide for non-members / non-owners (mirrors LLMConfigController).
 *
 * Error mapping:
 *   The service layer throws {@see SpeechProviderConfigException}
 *   for the cases the HTTP layer cares about: notFound (404),
 *   forbidden (403), validation (422). {@see mapException()} converts
 *   each to the wire envelope so the per-route handlers stay under
 *   the S1142 return-count ceiling and the JSON shape is identical
 *   across endpoints.
 *
 * The controller stays a thin HTTP layer; the service owns auth,
 * scope resolution, schema validation, and persistence.
 */
final class SpeechProviderConfigController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly SpeechProviderConfigService $service,
        private readonly SpeechToTextRegistry $registry,
        private readonly AgentServiceInterface $agentService,
    ) {}

    /**
     * GET /api/v1/speech/provider-configs/schema
     *
     * Registered STT classes + per-class `#[ToolSetting]` schemas.
     * Drives the dynamic create-config form in Settings → Speech.
     * Mirrors {@see LLMConfigController::drivers()}.
     */
    #[OA\Get(
        path: '/api/v1/speech/provider-configs/schema',
        summary: 'Registered speech-to-text provider classes with their settings schemas',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Provider schemas',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'providers',
                                    type: 'array',
                                    items: new OA\Items(type: 'object'),
                                ),
                            ],
                            type: 'object',
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function schema(): JsonResponse
    {
        return new JsonResponse(['data' => ['providers' => $this->service->getSchema($this->registry)]]);
    }

    /**
     * GET /api/v1/speech/provider-configs
     */
    #[OA\Get(
        path: '/api/v1/speech/provider-configs',
        summary: 'List speech provider configurations visible to the caller',
        parameters: [
            new OA\Parameter(
                name: 'group_id',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer'),
                description: 'When set, returns the speech provider configs attached to that group. The caller must be a member of the group or a global admin; non-members receive an empty list.',
            ),
            new OA\Parameter(
                name: 'agent_id',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer'),
                description: 'When set, returns only the configs valid for that agent\'s principal scope (configs scoped to the agent\'s owning principal, plus every global config). Use this on the agent-settings page so a user-owned agent does not surface configs owned by groups the caller happens to belong to.',
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Configs',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'configs',
                                    type: 'array',
                                    items: new OA\Items(type: 'object'),
                                ),
                            ],
                            type: 'object',
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $agentId = $this->parseAgentId($request);
        if ($agentId !== null) {
            return $this->indexForAgent($userId, $agentId);
        }

        $configs = $this->service->getConfigurationsForUser($userId);
        return new JsonResponse(['data' => ['configs' => $configs]]);
    }

    /**
     * When `?agent_id=N` is present, narrow the response to the agent's
     * principal scope. Visibility: the agent must be retrievable via
     * {@see AgentServiceInterface::getAgent()} (which checks ownership)
     * OR the caller must be an admin; otherwise the response is an
     * empty list (existence-hide).
     */
    private function indexForAgent(int $userId, int $agentId): JsonResponse
    {
        $isAdmin = $this->authService->isAdmin();
        if (!$isAdmin) {
            $agent = $this->agentService->getAgent($agentId, $userId);
            if ($agent === null) {
                return new JsonResponse(['data' => ['configs' => []]]);
            }
        }

        $configs = $this->service->getConfigurationsForAgent($agentId);
        return new JsonResponse(['data' => ['configs' => $configs]]);
    }

    /**
     * Parse a positive integer `?agent_id=N` query parameter. Returns
     * `null` when missing, negative, zero, or non-numeric; non-positive
     * values yield `null` so the controller falls back to the unscoped
     * `getConfigurationsForUser()` path instead of erroring.
     */
    private function parseAgentId(Request $request): ?int
    {
        $raw = $request->query->get('agent_id');
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            return null;
        }
        return (int) $value;
    }

    private function unauthenticated(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'AUTH_REQUIRED', 'message' => 'Authentication required.']],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    /**
     * POST /api/v1/speech/provider-configs
     */
    #[OA\Post(
        path: '/api/v1/speech/provider-configs',
        summary: 'Create a speech provider configuration',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['provider_class', 'settings'],
                properties: [
                    new OA\Property(property: 'provider_class', type: 'string'),
                    new OA\Property(property: 'display_name', type: 'string'),
                    new OA\Property(property: 'is_global', type: 'boolean', description: 'Admin-only. When true, the row is the global config; principal_id will be null.'),
                    new OA\Property(
                        property: 'principal_id',
                        type: 'integer',
                        nullable: true,
                        description: 'When set, the config is scoped to this principal (user or group). Caller must control the principal.',
                    ),
                    new OA\Property(property: 'scope', type: 'string', enum: ['user', 'group', 'global', 'agent']),
                    new OA\Property(property: 'group_id', type: 'integer'),
                    new OA\Property(property: 'settings', type: 'object'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created config'),
            new OA\Response(response: 403, description: 'SPEECH_PROVIDER_CONFIG_FORBIDDEN'),
            new OA\Response(response: 404, description: 'SPEECH_PROVIDER_CONFIG_NOT_FOUND'),
            new OA\Response(response: 422, description: 'SPEECH_PROVIDER_CONFIG_INVALID'),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $body = $this->decodeBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $userId = $this->requireUserId();
        $isAdmin = $this->authService->isAdmin();

        $resolved = $this->resolveScopeAndPrincipal($body, $userId, $isAdmin);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        return $this->createAndRespond($userId, $resolved, $isAdmin);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createAndRespond(int $userId, array $body, bool $isAdmin): JsonResponse
    {
        try {
            $config = $this->service->createConfiguration($userId, $body, $isAdmin);
        } catch (SpeechProviderConfigException $e) {
            return $this->mapException($e);
        }

        return new JsonResponse(
            ['data' => ['config' => $this->service->configResource($config)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * PUT /api/v1/speech/provider-configs/{id}
     */
    #[OA\Put(
        path: '/api/v1/speech/provider-configs/{id}',
        summary: 'Update an existing speech provider configuration',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'display_name', type: 'string'),
                    new OA\Property(property: 'settings', type: 'object'),
                ],
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated config'),
            new OA\Response(response: 403, description: 'SPEECH_PROVIDER_CONFIG_FORBIDDEN'),
            new OA\Response(response: 404, description: 'SPEECH_PROVIDER_CONFIG_NOT_FOUND'),
            new OA\Response(response: 422, description: 'SPEECH_PROVIDER_CONFIG_INVALID'),
        ],
    )]
    public function update(int $id, Request $request): JsonResponse
    {
        $body = $this->decodeBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $userId = $this->requireUserId();
        try {
            $config = $this->service->updateConfiguration($id, $userId, $body, $this->authService->isAdmin());
        } catch (SpeechProviderConfigException $e) {
            return $this->mapException($e);
        }

        return new JsonResponse(['data' => ['config' => $this->service->configResource($config)]]);
    }

    /**
     * DELETE /api/v1/speech/provider-configs/{id}
     */
    #[OA\Delete(
        path: '/api/v1/speech/provider-configs/{id}',
        summary: 'Delete a speech provider configuration',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 403, description: 'SPEECH_PROVIDER_CONFIG_FORBIDDEN'),
            new OA\Response(response: 404, description: 'SPEECH_PROVIDER_CONFIG_NOT_FOUND'),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        $userId = $this->requireUserId();
        try {
            $deleted = $this->service->deleteConfiguration($id, $userId, $this->authService->isAdmin());
        } catch (SpeechProviderConfigException $e) {
            return $this->mapException($e);
        }

        if (!$deleted) {
            return $this->notFound($id);
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * POST /api/v1/speech/provider-configs/{id}/set-default
     *
     * CRITICAL: this is registered as `/set-default` literal. The
     * router's id segment would otherwise swallow "set-default" as
     * a numeric id. See {@see SpeechRouteDefinitions}.
     */
    #[OA\Post(
        path: '/api/v1/speech/provider-configs/{id}/set-default',
        summary: 'Mark a global speech provider configuration as default',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated config (with is_default=true)'),
            new OA\Response(response: 403, description: 'SPEECH_PROVIDER_CONFIG_FORBIDDEN'),
            new OA\Response(response: 404, description: 'SPEECH_PROVIDER_CONFIG_NOT_FOUND'),
        ],
    )]
    public function setDefault(int $id): JsonResponse
    {
        $userId = $this->requireUserId();
        try {
            $config = $this->service->setDefaultConfiguration($id, $userId, $this->authService->isAdmin());
        } catch (SpeechProviderConfigException $e) {
            return $this->mapException($e);
        }

        return new JsonResponse(['data' => ['config' => $this->service->configResource($config)]]);
    }

    /**
     * Decode the JSON body or return a 422 envelope — callers check the
     * type and return early on the error response.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeBody(Request $request): array|JsonResponse
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }
        $decoded = $this->decodeJsonBody($content);
        if (!is_array($decoded)) {
            return $this->validationError('Request body must be valid JSON.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function decodeJsonBody(string $content): mixed
    {
        try {
            return json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * Translate the SPA's wire shape (`scope: 'group' + group_id`,
     * `scope: 'global'`, or neither) into the row-level fields the
     * service + persistence layers expect. Returns the rewritten body
     * on success, or a 403 JsonResponse when the caller cannot manage
     * the target group.
     *
     * - `scope: 'group'` → resolves `group_id` (groups.id) to a
     *   `principal_id` (principals.id) via the service's
     *   {@see SpeechProviderConfigService::resolveGroupPrincipal()}.
     *   `is_global` stays false. Missing/invalid `group_id` → 403.
     * - `scope: 'global'` → clears `principal_id` and forces
     *   `is_global: true` so the persistence layer writes the row as
     *   global regardless of what `principal_id` was supplied.
     * - `scope: 'user'` (or omitted) → leaves the body untouched.
     *   `createConfiguration()` defaults `principal_id` to the
     *   caller's user-principal when none was supplied.
     *
     * The `scope` and `group_id` keys are stripped before persistence so
     * they never reach the validator's required-key check or the
     * settings encoder.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|JsonResponse
     */
    private function resolveScopeAndPrincipal(array $body, int $userId, bool $isAdmin): array|JsonResponse
    {
        $scope = $body['scope'] ?? null;
        if ($scope === 'global') {
            unset($body['scope'], $body['group_id']);
            $body['is_global'] = true;
            $body['principal_id'] = null;
            return $body;
        }
        if ($scope !== 'group') {
            unset($body['scope'], $body['group_id']);
            return $body;
        }
        // Pull `group_id` off the body BEFORE stripping so the resolver
        // helper doesn't have to re-parse it from a now-empty field.
        $groupIdRaw = $body['group_id'] ?? null;
        unset($body['scope'], $body['group_id']);
        return $this->resolveGroupScopeBody($body, $userId, $isAdmin, is_int($groupIdRaw) ? $groupIdRaw : 0);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|JsonResponse
     */
    private function resolveGroupScopeBody(array $body, int $userId, bool $isAdmin, int $groupId): array|JsonResponse
    {
        if ($groupId <= 0) {
            return $this->forbidden();
        }
        $principalId = $this->service->resolveGroupPrincipal($groupId, $userId, $isAdmin);
        if ($principalId === null) {
            return $this->forbidden();
        }
        $body['principal_id'] = $principalId;
        $body['is_global'] = false;
        return $body;
    }

    private function requireUserId(): int
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return 0; // endpoints handle the 0 user case below
        }
        return $userId;
    }

    /**
     * Translate the service-layer exception into the wire envelope
     * with the documented HTTP status. Extracted from the per-route
     * try/catch blocks so each handler stays under the S1142 return
     * ceiling.
     */
    private function mapException(SpeechProviderConfigException $e): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]],
            $e->statusCode,
        );
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'SPEECH_PROVIDER_CONFIG_FORBIDDEN', 'message' => 'Not authorised for this speech provider configuration.']],
            Response::HTTP_FORBIDDEN,
        );
    }

    private function notFound(int $id): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'SPEECH_PROVIDER_CONFIG_NOT_FOUND', 'message' => "Speech provider configuration {$id} not found."]],
            Response::HTTP_NOT_FOUND,
        );
    }

    private function validationError(string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'SPEECH_PROVIDER_CONFIG_INVALID', 'message' => $message]],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

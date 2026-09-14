<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
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
 *   PUT    /api/v1/speech/preference               — set / clear the caller's preferred STT
 *                                                  config on principal_preferences (FK)
 *   GET    /api/v1/speech/preference?scope=user|group[&group_id=N]
 *                                              — read the current preference
 *
 * Auth model:
 *   - Global mutations require admin (AuthService::isAdmin).
 *   - User-scope writes require principal_id = caller user-principal.
 *   - Group-scope writes require GroupService::callerCanManage(groupId, userId, isAdmin).
 *   - Reads are existence-hide for non-members / non-owners (mirrors LLMConfigController).
 *
 * The controller stays a thin HTTP layer; the service owns auth,
 * scope resolution, schema validation, and persistence.
 */
final class SpeechProviderConfigController
{
    private const VALIDATION_GROUP_ID_REQUIRED = 'group_id must be a positive integer when scope="group".';
    private const VALIDATION_GROUP_ID_FORBIDDEN = 'group_id may only be set when scope="group".';

    public function __construct(
        private readonly AuthService $authService,
        private readonly SpeechProviderConfigService $service,
        private readonly SpeechToTextRegistry $registry,
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
    public function index(): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $configs = $this->service->getConfigurationsForUser($userId);
        return new JsonResponse(['data' => ['configs' => $configs]]);
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
        $config = $this->service->createConfiguration($userId, $body, $this->authService->isAdmin());

        if ($config === null) {
            return $this->forbidden();
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
        $config = $this->service->updateConfiguration($id, $userId, $body, $this->authService->isAdmin());

        if ($config === null) {
            return $this->forbidden();
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
        $deleted = $this->service->deleteConfiguration($id, $userId, $this->authService->isAdmin());

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
        $config = $this->service->setDefaultConfiguration($id, $userId, $this->authService->isAdmin());

        if ($config === null) {
            return $this->forbidden();
        }

        return new JsonResponse(['data' => ['config' => $this->service->configResource($config)]]);
    }

    /**
     * GET /api/v1/speech/preference
     */
    #[OA\Get(
        path: '/api/v1/speech/preference',
        summary: "Read the caller's preferred speech-to-text provider",
        parameters: [
            new OA\Parameter(
                name: 'scope',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['user', 'group']),
            ),
            new OA\Parameter(name: 'group_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Current preference row',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'preference',
                                    properties: [
                                        new OA\Property(property: 'config_id', type: 'integer', nullable: true),
                                        new OA\Property(property: 'scope', type: 'string'),
                                        new OA\Property(property: 'group_id', type: 'integer', nullable: true),
                                    ],
                                    type: 'object',
                                ),
                            ],
                            type: 'object',
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 403, description: 'SPEECH_PROVIDER_CONFIG_FORBIDDEN'),
            new OA\Response(response: 422, description: 'SPEECH_PROVIDER_CONFIG_INVALID'),
        ],
    )]
    public function getPreference(Request $request): JsonResponse
    {
        $validated = $this->validateGetPreferenceInput($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $userId = $this->requireUserId();
        $config = $this->service->resolvePreferredConfig(
            $userId,
            $this->authService->isAdmin(),
            $validated->groupId,
            $validated->scope,
        );

        return new JsonResponse(['data' => [
            'preference' => [
                'config_id' => $config?->id,
                'scope' => $validated->scope,
                'group_id' => $validated->groupId,
            ],
        ]]);
    }

    private function validateGetPreferenceInput(Request $request): PreferredPreferenceInput|JsonResponse
    {
        $params = $request->query->all();
        $scope = $this->stringField($params, 'scope');
        if ($scope instanceof JsonResponse) {
            return $scope;
        }
        if ($scope !== 'user' && $scope !== 'group') {
            return $this->validationError('scope must be "user" or "group".');
        }

        $groupId = $this->optionalIntQueryParam($request, 'group_id');
        if ($groupId instanceof JsonResponse) {
            return $groupId;
        }
        if ($scope === 'group' && $groupId === null) {
            return $this->validationError(self::VALIDATION_GROUP_ID_REQUIRED);
        }
        if ($scope !== 'group' && $request->query->has('group_id')) {
            return $this->validationError(self::VALIDATION_GROUP_ID_FORBIDDEN);
        }

        return new PreferredPreferenceInput($scope, $groupId, null);
    }

    /**
     * PUT /api/v1/speech/preference
     */
    #[OA\Put(
        path: '/api/v1/speech/preference',
        summary: "Set or clear the caller's preferred speech-to-text configuration",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['scope', 'config_id'],
                properties: [
                    new OA\Property(property: 'config_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'scope', type: 'string', enum: ['user', 'group']),
                    new OA\Property(property: 'group_id', type: 'integer', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated preference row'),
            new OA\Response(response: 403, description: 'SPEECH_PROVIDER_CONFIG_FORBIDDEN'),
            new OA\Response(response: 422, description: 'SPEECH_PROVIDER_CONFIG_INVALID'),
        ],
    )]
    public function setPreferred(Request $request): JsonResponse
    {
        $validated = $this->validatePreferredInput($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $userId = $this->requireUserId();
        $principalId = $this->resolvePrincipalIdForScope($userId, $validated->scope, $validated->groupId);
        if ($principalId <= 0) {
            return $this->forbidden();
        }

        return $this->applyPreferredConfigWrite(
            $principalId,
            $validated->configId,
            $userId,
            $validated->scope,
            $validated->groupId,
        );
    }

    /**
     * Validate the entire PUT body for /api/v1/speech/preference in one
     * pass. Returns the cleaned (scope, groupId, configId) triple on
     * success, or a 422 JsonResponse on the first failure.
     *
     * @return PreferredPreferenceInput|JsonResponse
     */
    private function validatePreferredInput(Request $request): PreferredPreferenceInput|JsonResponse
    {
        $body = $this->decodeBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $scope = $this->stringField($body, 'scope');
        if ($scope instanceof JsonResponse) {
            return $scope;
        }
        if ($scope !== 'user' && $scope !== 'group') {
            return $this->validationError('scope must be "user" or "group".');
        }

        $configId = array_key_exists('config_id', $body) ? $body['config_id'] : null;
        if ($configId !== null && (!is_int($configId) || $configId <= 0)) {
            return $this->validationError('Field "config_id" must be a positive integer or null.');
        }

        $groupId = $this->cleanGroupIdForScope($body, $scope);
        if ($groupId instanceof JsonResponse) {
            return $groupId;
        }

        return new PreferredPreferenceInput($scope, $groupId, $configId);
    }

    /**
     * @param array<string, mixed> $body
     * @return int|JsonResponse|null
     */
    private function cleanGroupIdForScope(array $body, string $scope): int|JsonResponse|null
    {
        if ($scope === 'group') {
            if (!isset($body['group_id']) || !is_int($body['group_id']) || $body['group_id'] <= 0) {
                return $this->validationError(self::VALIDATION_GROUP_ID_REQUIRED);
            }
            return $body['group_id'];
        }
        if (array_key_exists('group_id', $body)) {
            return $this->validationError(self::VALIDATION_GROUP_ID_FORBIDDEN);
        }
        return null;
    }

    private function resolvePrincipalIdForScope(int $userId, string $scope, ?int $groupId): int
    {
        $principalService = new PrincipalService(new PrincipalResolver());
        if ($scope === 'user') {
            return (int) $principalService->ensureUserPrincipal($userId)->id;
        }
        // $scope === 'group' here; cleanGroupIdForScope guarantees
        // $groupId is a positive int.
        $groupPrincipal = $principalService->principalForGroup((int) $groupId);
        return $groupPrincipal !== null ? (int) $groupPrincipal->id : 0;
    }

    private function applyPreferredConfigWrite(
        int $principalId,
        ?int $configId,
        int $userId,
        string $scope,
        ?int $groupId,
    ): JsonResponse {
        $writeResult = $this->writePreferredConfig($principalId, $configId, $userId);
        if ($writeResult !== null) {
            return $writeResult;
        }
        return new JsonResponse(['data' => [
            'preference' => [
                'config_id' => $configId,
                'scope' => $scope,
                'group_id' => $groupId,
            ],
        ]]);
    }

    private function writePreferredConfig(int $principalId, ?int $configId, int $userId): ?JsonResponse
    {
        if ($configId === null) {
            $this->service->unsetPrincipalPreferredConfig($principalId);
            return null;
        }
        $ok = $this->service->setPrincipalPreferredConfig($principalId, $configId, $userId);
        return $ok ? null : $this->forbidden();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

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
        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->validationError('Request body must be valid JSON.');
        }
        if (!is_array($decoded)) {
            return $this->validationError('Request body must be valid JSON.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     * @return string|JsonResponse
     */
    private function stringField(array $body, string $field): string|JsonResponse
    {
        $raw = $body[$field] ?? null;
        if (!is_string($raw) || $raw === '') {
            return $this->validationError("Field '{$field}' is required and must be a non-empty string.");
        }
        return $raw;
    }

    /**
     * @return int|JsonResponse
     */
    private function optionalIntQueryParam(Request $request, string $name): int|JsonResponse|null
    {
        $raw = $request->query->get($name);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (int) $raw <= 0) {
            return $this->validationError("Query parameter '{$name}' must be a positive integer when present.");
        }
        return (int) $raw;
    }

    private function requireUserId(): int
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return 0; // endpoints handle the 0 user case below
        }
        return $userId;
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

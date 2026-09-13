<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Http\Exceptions\SpeechProviderConfigException;
use Spora\Services\SpeechProviderConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API for speech-to-text provider configurations.
 *
 * Endpoints (all behind `AuthMiddleware`; mutations also behind `CsrfMiddleware`):
 *   GET    /api/v1/speech/provider-configs?group_id=N
 *                                              — list (admin: globals; user: own overrides;
 *                                                with group_id: that group's configs)
 *   GET    /api/v1/speech/provider-configs/schema   — provider-class picker schema
 *   POST   /api/v1/speech/provider-configs          — create or update a config (upsert)
 *   POST   /api/v1/speech/provider-configs/set-default
 *                                              — mark one config as the default at its scope
 *   PUT    /api/v1/speech/provider-configs/{id}     — update an existing config
 *   DELETE /api/v1/speech/provider-configs/{id}     — delete a config
 *   PUT    /api/v1/speech/preference               — set / clear the caller's preferred STT
 *                                                  class on principal_preferences
 *
 * Storage rules (modeled in {@see SpeechProviderConfigService}):
 *   - `scope = 'global'` writes to `tool_configurations` (admin-only).
 *   - `scope = 'user'`   writes to `tool_user_settings` keyed by the
 *     caller's user-principal id (auto-materialised on demand).
 *   - `scope = 'group'`  writes to `tool_user_settings` keyed by the
 *     group-principal id of the `group_id` field in the body. The
 *     caller must be group admin OR global admin
 *     (`GroupService::callerCanManage()`).
 *
 * The controller stays a thin HTTP layer. The service owns auth,
 * scope resolution, schema validation, and the (scope, table) mapping.
 * Errors raise {@see SpeechProviderConfigException} which is caught
 * once per endpoint and mapped to the `{error: {code, message}}`
 * envelope.
 */
final class SpeechProviderConfigController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly SpeechProviderConfigService $configService,
    ) {}

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
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = $this->requireUserId();
            $groupId = $this->optionalIntQueryParam($request, 'group_id');
            $configs = $this->configService->listConfigs(
                userId: $userId,
                isAdmin: $this->authService->isAdmin(),
                groupId: $groupId,
            );
        } catch (SpeechProviderConfigException $e) {
            return $this->error($e->statusCode, $e->errorCode, $e->getMessage());
        }
        return new JsonResponse(['data' => ['configs' => $configs]]);
    }

    #[OA\Get(
        path: '/api/v1/speech/provider-configs/schema',
        summary: 'Speech provider picker schema (one entry per registered provider class)',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Providers schema',
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
        return new JsonResponse(['data' => ['providers' => $this->configService->getSchema()]]);
    }

    #[OA\Post(
        path: '/api/v1/speech/provider-configs',
        summary: 'Create or update a speech provider configuration (upsert)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['provider_class', 'scope', 'settings'],
                properties: [
                    new OA\Property(property: 'provider_class', type: 'string'),
                    new OA\Property(property: 'scope', type: 'string', enum: ['global', 'user', 'group']),
                    new OA\Property(
                        property: 'group_id',
                        type: 'integer',
                        nullable: true,
                        description: 'Required when scope="group". Names the group the config is attached to. Caller must be group admin or global admin.',
                    ),
                    new OA\Property(property: 'settings', type: 'object'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Upserted config'),
            new OA\Response(response: 403, description: 'SPEECH_PROVIDER_CONFIG_FORBIDDEN'),
            new OA\Response(response: 404, description: 'SPEECH_PROVIDER_CONFIG_NOT_FOUND'),
            new OA\Response(response: 422, description: 'SPEECH_PROVIDER_CONFIG_INVALID'),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        try {
            $body = $this->decodeBody($request);
            $userId = $this->requireUserId();
            $isAdmin = $this->authService->isAdmin();

            $providerClass = $this->stringField($body, 'provider_class');
            $scope = $this->stringField($body, 'scope');
            $rawSettings = $body['settings'] ?? [];
            $settings = is_array($rawSettings) ? $rawSettings : [];

            $groupId = null;
            if ($scope === 'group') {
                if (!isset($body['group_id']) || !is_int($body['group_id']) || $body['group_id'] <= 0) {
                    throw SpeechProviderConfigException::validation(
                        'group_id must be a positive integer when scope="group".',
                    );
                }
                $groupId = $body['group_id'];
            } elseif (array_key_exists('group_id', $body)) {
                throw SpeechProviderConfigException::validation(
                    'group_id may only be set when scope="group".',
                );
            }

            $config = $this->configService->upsertConfig(
                userId: $userId,
                isAdmin: $isAdmin,
                providerClass: $providerClass,
                scope: $scope,
                settings: $settings,
                groupId: $groupId,
            );
        } catch (SpeechProviderConfigException $e) {
            return $this->error($e->statusCode, $e->errorCode, $e->getMessage());
        }
        return new JsonResponse(['data' => ['config' => $config]]);
    }

    #[OA\Put(
        path: '/api/v1/speech/provider-configs/{id}',
        summary: 'Update an existing speech provider configuration',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['settings'],
                properties: [
                    new OA\Property(property: 'settings', type: 'object'),
                ],
            ),
        ),
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
            ),
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
        try {
            $body = $this->decodeBody($request);
            $userId = $this->requireUserId();
            $isAdmin = $this->authService->isAdmin();

            $rawSettings = $body['settings'] ?? [];
            $settings = is_array($rawSettings) ? $rawSettings : [];

            $config = $this->configService->updateConfig($userId, $isAdmin, $id, $settings);
        } catch (SpeechProviderConfigException $e) {
            return $this->error($e->statusCode, $e->errorCode, $e->getMessage());
        }
        return new JsonResponse(['data' => ['config' => $config]]);
    }

    #[OA\Delete(
        path: '/api/v1/speech/provider-configs/{id}',
        summary: 'Delete a speech provider configuration',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 403, description: 'SPEECH_PROVIDER_CONFIG_FORBIDDEN'),
            new OA\Response(response: 404, description: 'SPEECH_PROVIDER_CONFIG_NOT_FOUND'),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        try {
            $userId = $this->requireUserId();
            $isAdmin = $this->authService->isAdmin();
            $deleted = $this->configService->deleteConfig($userId, $isAdmin, $id);
        } catch (SpeechProviderConfigException $e) {
            return $this->error($e->statusCode, $e->errorCode, $e->getMessage());
        }
        if (!$deleted) {
            return $this->error(
                Response::HTTP_NOT_FOUND,
                'SPEECH_PROVIDER_CONFIG_NOT_FOUND',
                "Speech provider configuration {$id} not found.",
            );
        }
        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    #[OA\Post(
        path: '/api/v1/speech/provider-configs/set-default',
        summary: 'Mark a speech provider configuration as default at its scope',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['provider_class', 'scope'],
                properties: [
                    new OA\Property(property: 'provider_class', type: 'string'),
                    new OA\Property(property: 'scope', type: 'string', enum: ['global', 'user', 'group']),
                    new OA\Property(
                        property: 'group_id',
                        type: 'integer',
                        nullable: true,
                        description: 'Required when scope="group".',
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated config (with is_default=true)'),
            new OA\Response(response: 403, description: 'SPEECH_PROVIDER_CONFIG_FORBIDDEN'),
            new OA\Response(response: 404, description: 'SPEECH_PROVIDER_CONFIG_NOT_FOUND'),
            new OA\Response(response: 422, description: 'SPEECH_PROVIDER_CONFIG_INVALID'),
        ],
    )]
    public function setDefault(Request $request): JsonResponse
    {
        try {
            $body = $this->decodeBody($request);
            $userId = $this->requireUserId();
            $isAdmin = $this->authService->isAdmin();

            $providerClass = $this->stringField($body, 'provider_class');
            $scope = $this->stringField($body, 'scope');

            $groupId = null;
            if ($scope === 'group') {
                if (!isset($body['group_id']) || !is_int($body['group_id']) || $body['group_id'] <= 0) {
                    throw SpeechProviderConfigException::validation(
                        'group_id must be a positive integer when scope="group".',
                    );
                }
                $groupId = $body['group_id'];
            } elseif (array_key_exists('group_id', $body)) {
                throw SpeechProviderConfigException::validation(
                    'group_id may only be set when scope="group".',
                );
            }

            $config = $this->configService->setDefaultConfig(
                userId: $userId,
                isAdmin: $isAdmin,
                providerClass: $providerClass,
                scope: $scope,
                groupId: $groupId,
            );
        } catch (SpeechProviderConfigException $e) {
            return $this->error($e->statusCode, $e->errorCode, $e->getMessage());
        }
        return new JsonResponse(['data' => ['config' => $config]]);
    }

    #[OA\Put(
        path: '/api/v1/speech/preference',
        summary: "Set or clear the caller's preferred speech-to-text provider class",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['scope'],
                properties: [
                    new OA\Property(property: 'provider_class', type: 'string', nullable: true),
                    new OA\Property(property: 'scope', type: 'string', enum: ['user', 'group']),
                    new OA\Property(
                        property: 'group_id',
                        type: 'integer',
                        nullable: true,
                        description: 'Required when scope="group".',
                    ),
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
        try {
            $body = $this->decodeBody($request);
            $userId = $this->requireUserId();
            $isAdmin = $this->authService->isAdmin();

            $scope = $this->stringField($body, 'scope');
            $providerClass = null;
            if (array_key_exists('provider_class', $body) && $body['provider_class'] !== null) {
                $raw = $body['provider_class'];
                if (!is_string($raw) || $raw === '') {
                    throw SpeechProviderConfigException::validation(
                        'Field \'provider_class\' must be a non-empty string or null.',
                    );
                }
                $providerClass = $raw;
            }

            $groupId = null;
            if ($scope === 'group') {
                if (!isset($body['group_id']) || !is_int($body['group_id']) || $body['group_id'] <= 0) {
                    throw SpeechProviderConfigException::validation(
                        'group_id must be a positive integer when scope="group".',
                    );
                }
                $groupId = $body['group_id'];
            } elseif (array_key_exists('group_id', $body)) {
                throw SpeechProviderConfigException::validation(
                    'group_id may only be set when scope="group".',
                );
            }

            $preference = $this->configService->setPreferredClass(
                userId: $userId,
                isAdmin: $isAdmin,
                providerClass: $providerClass,
                scope: $scope,
                groupId: $groupId,
            );
        } catch (SpeechProviderConfigException $e) {
            return $this->error($e->statusCode, $e->errorCode, $e->getMessage());
        }
        return new JsonResponse(['data' => ['preference' => $preference]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }
        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SpeechProviderConfigException::validation('Request body must be valid JSON.');
        }
        if (!is_array($decoded)) {
            throw SpeechProviderConfigException::validation('Request body must be a JSON object.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Read an optional integer query parameter from the request. Returns
     * `null` when the parameter is absent or empty; throws 422 when the
     * parameter is present but not a positive integer (callers explicitly
     * requesting a numeric id expect a hard failure on a typo'd value).
     */
    private function optionalIntQueryParam(Request $request, string $name): ?int
    {
        $raw = $request->query->get($name);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (int) $raw <= 0) {
            throw SpeechProviderConfigException::validation(
                "Query parameter '{$name}' must be a positive integer when present.",
            );
        }
        return (int) $raw;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $field): string
    {
        $raw = $body[$field] ?? null;
        if (!is_string($raw) || $raw === '') {
            throw SpeechProviderConfigException::validation(
                "Field '{$field}' is required and must be a non-empty string.",
            );
        }
        return $raw;
    }

    private function requireUserId(): int
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            throw SpeechProviderConfigException::forbidden('Authentication required.');
        }
        return $userId;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
        );
    }
}

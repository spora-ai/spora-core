<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\SpeechProviderConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET / PUT for the caller's preferred speech-to-text provider.
 *
 * Carved out of {@see SpeechProviderConfigController} so the umbrella
 * stays under the SonarCloud S1448 20-method threshold and so the
 * preference surface mirrors the LLM split (LLMConfigController +
 * UserPreferenceController + GroupPreferencesController):
 *
 *   GET    /api/v1/speech/preference?scope=user|group[&group_id=N]
 *                                              — read the current preference
 *   PUT    /api/v1/speech/preference               — set / clear the caller's
 *                                                  preferred STT config on
 *                                                  principal_preferences (FK)
 *
 * The write goes through {@see SpeechProviderConfigService} which
 * owns the auth + scope-resolution contract — this controller stays
 * a thin HTTP layer.
 */
final class SpeechPreferenceController
{
    private const VALIDATION_GROUP_ID_REQUIRED = 'group_id must be a positive integer when scope="group".';
    private const VALIDATION_GROUP_ID_FORBIDDEN = 'group_id may only be set when scope="group".';
    private const MSG_FORBIDDEN = 'Not authorised for this speech provider configuration.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly SpeechProviderConfigService $service,
    ) {}

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
        $scope = $this->requireScope($request->query->all());
        if ($scope instanceof JsonResponse) {
            return $scope;
        }
        $groupId = $this->optionalGroupIdForScope($request, $scope);
        if ($groupId instanceof JsonResponse) {
            return $groupId;
        }

        $userId = $this->requireUserId();
        $config = $this->service->resolvePreferredConfig(
            $userId,
            $this->authService->isAdmin(),
            $groupId,
            $scope,
        );

        return new JsonResponse(['data' => [
            'preference' => [
                'config_id' => $config?->id,
                'scope' => $scope,
                'group_id' => $groupId,
            ],
        ]]);
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
        $body = $this->decodeBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $scope = $this->requireScope($body);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }
        return $this->writeValidatedPreference($body, $scope);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function writeValidatedPreference(array $body, string $scope): JsonResponse
    {
        $configId = $this->extractConfigId($body);
        if ($configId instanceof JsonResponse) {
            return $configId;
        }
        $groupId = $this->cleanGroupIdForScope($body, $scope);
        if ($groupId instanceof JsonResponse) {
            return $groupId;
        }
        return $this->persistPreference($configId, $groupId, $scope);
    }

    private function persistPreference(?int $configId, ?int $groupId, string $scope): JsonResponse
    {
        $userId = $this->requireUserId();
        $principalId = $this->resolvePrincipalIdForScope($userId, $scope, $groupId);
        if ($principalId <= 0) {
            return $this->forbiddenResponse();
        }
        return $this->writePreferredConfig($principalId, $configId, $userId)
            ?? $this->buildPreferenceResponse($configId, $scope, $groupId);
    }

    private function buildPreferenceResponse(?int $configId, string $scope, ?int $groupId): JsonResponse
    {
        return new JsonResponse(['data' => [
            'preference' => [
                'config_id' => $configId,
                'scope' => $scope,
                'group_id' => $groupId,
            ],
        ]]);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function requireScope(array $source): string|JsonResponse
    {
        $scope = $source['scope'] ?? null;
        if (!is_string($scope) || $scope === '') {
            return $this->validationError("Field 'scope' is required and must be a non-empty string.");
        }
        if ($scope !== 'user' && $scope !== 'group') {
            return $this->validationError('scope must be "user" or "group".');
        }
        return $scope;
    }

    /**
     * GET helper. group_id is OPTIONAL — omitted defaults to "no
     * constraint" rather than an error. Used by {@see getPreference()}.
     *
     * @return int|JsonResponse|null
     */
    private function optionalGroupIdForScope(Request $request, string $scope): int|JsonResponse|null
    {
        $raw = $request->query->get('group_id');
        $groupIdInQuery = $raw !== null && $raw !== '';
        $groupId = null;
        if ($groupIdInQuery) {
            if (!is_numeric($raw) || (int) $raw <= 0) {
                return $this->validationError("Query parameter 'group_id' must be a positive integer when present.");
            }
            $groupId = (int) $raw;
        }
        return $this->optionalGroupIdViolation($scope, $groupId, $groupIdInQuery) ?? $groupId;
    }

    private function optionalGroupIdViolation(string $scope, ?int $groupId, bool $groupIdInQuery): ?JsonResponse
    {
        return match (true) {
            $scope === 'group' && $groupId === null => $this->validationError(self::VALIDATION_GROUP_ID_REQUIRED),
            $scope !== 'group' && $groupIdInQuery => $this->validationError(self::VALIDATION_GROUP_ID_FORBIDDEN),
            default => null,
        };
    }

    /**
     * PUT helper. group_id is REQUIRED when scope='group', FORBIDDEN
     * otherwise — strict semantics to mirror the PUT body's
     * "(scope, group_id)" coupling.
     *
     * @param array<string, mixed> $body
     * @return int|JsonResponse|null
     */
    private function cleanGroupIdForScope(array $body, string $scope): int|JsonResponse|null
    {
        if ($scope === 'group') {
            return $this->extractRequiredGroupId($body);
        }
        if (array_key_exists('group_id', $body)) {
            return $this->validationError(self::VALIDATION_GROUP_ID_FORBIDDEN);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractRequiredGroupId(array $body): int|JsonResponse
    {
        if (!isset($body['group_id']) || !is_int($body['group_id']) || $body['group_id'] <= 0) {
            return $this->validationError(self::VALIDATION_GROUP_ID_REQUIRED);
        }
        return $body['group_id'];
    }

    /**
     * @param array<string, mixed> $body
     * @return int|null|JsonResponse
     */
    private function extractConfigId(array $body): int|null|JsonResponse
    {
        $raw = array_key_exists('config_id', $body) ? $body['config_id'] : null;
        if ($raw === null) {
            return null;
        }
        if (!is_int($raw) || $raw <= 0) {
            return $this->validationError('Field "config_id" must be a positive integer or null.');
        }
        return $raw;
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

    private function writePreferredConfig(int $principalId, ?int $configId, int $userId): ?JsonResponse
    {
        if ($configId === null) {
            $this->service->unsetPrincipalPreferredConfig($principalId);
            return null;
        }
        $ok = $this->service->setPrincipalPreferredConfig($principalId, $configId, $userId);
        return $ok ? null : $this->forbiddenResponse();
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeBody(Request $request): array|JsonResponse
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }
        $decoded = null;
        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // fall through to the validation-error below.
        }
        if (!is_array($decoded)) {
            return $this->validationError('Request body must be valid JSON.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function requireUserId(): int
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return 0; // endpoints handle the 0 user case below
        }
        return $userId;
    }

    private function forbiddenResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'SPEECH_PROVIDER_CONFIG_FORBIDDEN', 'message' => self::MSG_FORBIDDEN]],
            Response::HTTP_FORBIDDEN,
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

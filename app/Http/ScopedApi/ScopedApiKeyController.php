<?php

declare(strict_types=1);

namespace Spora\Http\ScopedApi;

use OpenApi\Attributes as OA;
use Spora\Auth\ApiKeyIssuer;
use Spora\Auth\ApiKeyScope;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\ApiKey;
use Spora\Models\Group;
use Spora\Services\GroupService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minting and revoking the group-scoped and global-scoped API keys, from
 * the admin UI.
 *
 * Session-authenticated and CSRF-protected, like the rest of the admin: this
 * is the surface an operator uses, and it lives behind the same
 * authentication as the rest of the admin. Always registered, so a key can
 * be revoked even on an instance whose scope is switched off.
 *
 * The plaintext key is returned exactly once, by the store methods. There is
 * no endpoint that can return it again, and the row stores only a SHA-256
 * digest, so a lost key is replaced rather than recovered.
 *
 * Who may mint what:
 *
 *   - a group key needs *owner* or *admin* of the group;
 *   - a global key needs a global admin.
 *
 * A global key is the widest credential Spora issues: it reads every group's
 * chats. Minting one is deliberately not a group-level right.
 */
final class ScopedApiKeyController
{
    use JsonControllerHelpers;

    private const MAX_NAME_LENGTH = 100;

    private const MSG_CANNOT_MANAGE_GROUP = 'You cannot manage API keys for this group.';

    private const MSG_NOT_GLOBAL_ADMIN = 'Only a global admin can manage global API keys.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly GroupService $groupService,
        private readonly ApiKeyIssuer $issuer,
    ) {}

    #[OA\Get(
        path: '/api/v1/groups/{groupId}/api-keys',
        summary: "List a group's API keys",
        tags: ['Groups'],
    )]
    #[OA\Response(response: 200, description: 'Key metadata. Secrets are never returned.')]
    #[OA\Response(response: 403, description: 'The caller cannot manage this group.')]
    public function groupIndex(Request $request): JsonResponse
    {
        $denied = $this->guardGroup($request);

        if ($denied !== null) {
            return $denied;
        }

        $groupId = (int) $request->attributes->get('groupId', 0);
        $keys = ApiKey::where('scope', ApiKeyScope::Group->value)
            ->where('group_id', $groupId)
            ->orderByDesc('created_at')
            ->get();

        return new JsonResponse([
            'data' => [
                'keys' => $keys->map(fn(ApiKey $key): array => $this->present($key))->all(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/groups/{groupId}/api-keys',
        summary: 'Mint a group API key',
        tags: ['Groups'],
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['name'],
        properties: [new OA\Property(property: 'name', type: 'string', maxLength: 100)],
    ))]
    #[OA\Response(response: 201, description: 'The key, with its plaintext secret. Shown once.')]
    #[OA\Response(response: 403, description: 'The caller cannot manage this group.')]
    #[OA\Response(response: 422, description: 'The name was missing or too long.')]
    public function groupStore(Request $request): JsonResponse
    {
        $denied = $this->guardGroup($request);

        if ($denied !== null) {
            return $denied;
        }

        $groupId = (int) $request->attributes->get('groupId', 0);
        $body = $this->safeDecodeJson($request);
        $name = $body instanceof JsonResponse ? $body : $this->checkedName($body);

        if ($name instanceof JsonResponse) {
            return $name;
        }

        [$key, $plaintext] = $this->issuer->issue(
            ApiKeyScope::Group,
            $name,
            null,
            $groupId,
            $this->authService->currentUserId(),
        );

        return new JsonResponse(
            ['data' => ['key' => $this->present($key), 'secret' => $plaintext]],
            Response::HTTP_CREATED,
        );
    }

    #[OA\Delete(
        path: '/api/v1/groups/{groupId}/api-keys/{keyId}',
        summary: 'Revoke a group API key',
        tags: ['Groups'],
    )]
    #[OA\Response(response: 200, description: 'Revoked. Idempotent — the same body whether or not the key existed.')]
    #[OA\Response(response: 403, description: 'The caller cannot manage this group.')]
    public function groupDestroy(Request $request): JsonResponse
    {
        $denied = $this->guardGroup($request);

        if ($denied !== null) {
            return $denied;
        }

        $groupId = (int) $request->attributes->get('groupId', 0);
        $keyId = (string) $request->attributes->get('keyId', '');

        // Scoped by group, so a key id cannot be used to revoke another
        // group's key. The response does not say whether the row existed,
        // which is what stops this being a probe for which key ids are real.
        ApiKey::where('scope', ApiKeyScope::Group->value)
            ->where('group_id', $groupId)
            ->where('id', $keyId)
            ->delete();

        return new JsonResponse(['data' => ['revoked' => true, 'key_id' => $keyId]]);
    }

    #[OA\Get(
        path: '/api/v1/global-api-keys',
        summary: 'List the global API keys',
        tags: ['Global-api'],
    )]
    #[OA\Response(response: 200, description: 'Key metadata. Secrets are never returned.')]
    #[OA\Response(response: 403, description: 'The caller is not a global admin.')]
    public function globalIndex(Request $request): JsonResponse
    {
        $denied = $this->guardGlobal();

        if ($denied !== null) {
            return $denied;
        }

        $keys = ApiKey::where('scope', ApiKeyScope::Global->value)
            ->orderByDesc('created_at')
            ->get();

        return new JsonResponse([
            'data' => [
                'keys' => $keys->map(fn(ApiKey $key): array => $this->present($key))->all(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/global-api-keys',
        summary: 'Mint a global API key',
        tags: ['Global-api'],
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['name'],
        properties: [new OA\Property(property: 'name', type: 'string', maxLength: 100)],
    ))]
    #[OA\Response(response: 201, description: 'The key, with its plaintext secret. Shown once.')]
    #[OA\Response(response: 403, description: 'The caller is not a global admin.')]
    #[OA\Response(response: 422, description: 'The name was missing or too long.')]
    public function globalStore(Request $request): JsonResponse
    {
        $denied = $this->guardGlobal();

        if ($denied !== null) {
            return $denied;
        }

        $body = $this->safeDecodeJson($request);
        $name = $body instanceof JsonResponse ? $body : $this->checkedName($body);

        if ($name instanceof JsonResponse) {
            return $name;
        }

        [$key, $plaintext] = $this->issuer->issue(
            ApiKeyScope::Global,
            $name,
            null,
            null,
            $this->authService->currentUserId(),
        );

        return new JsonResponse(
            ['data' => ['key' => $this->present($key), 'secret' => $plaintext]],
            Response::HTTP_CREATED,
        );
    }

    #[OA\Delete(
        path: '/api/v1/global-api-keys/{keyId}',
        summary: 'Revoke a global API key',
        tags: ['Global-api'],
    )]
    #[OA\Response(response: 200, description: 'Revoked. Idempotent — the same body whether or not the key existed.')]
    #[OA\Response(response: 403, description: 'The caller is not a global admin.')]
    public function globalDestroy(Request $request): JsonResponse
    {
        $denied = $this->guardGlobal();

        if ($denied !== null) {
            return $denied;
        }

        $keyId = (string) $request->attributes->get('keyId', '');

        ApiKey::where('scope', ApiKeyScope::Global->value)
            ->where('id', $keyId)
            ->delete();

        return new JsonResponse(['data' => ['revoked' => true, 'key_id' => $keyId]]);
    }

    /**
     * @param array<string, mixed> $body
     * @return JsonResponse|string the trimmed name, or the 422 envelope
     */
    private function checkedName(array $body): JsonResponse|string
    {
        $name = $body['name'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            return $this->unprocessable('VALIDATION_ERROR', 'name is required.');
        }

        $name = trim($name);

        return mb_strlen($name) > self::MAX_NAME_LENGTH
            ? $this->unprocessable('VALIDATION_ERROR', 'name must be 100 characters or fewer.')
            : $name;
    }

    /**
     * @return array{id: string, scope: string, agent_id: ?int, group_id: ?int, name: string, last_used_at: ?string, created_at: ?string}
     */
    private function present(ApiKey $key): array
    {
        return [
            'id'           => $key->id,
            'scope'        => (string) $key->scope,
            'agent_id'     => $key->agent_id === null ? null : (int) $key->agent_id,
            'group_id'     => $key->group_id === null ? null : (int) $key->group_id,
            'name'         => $key->name,
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'created_at'   => $key->created_at?->toIso8601String(),
        ];
    }

    /**
     * Authenticate, then require the owner or admin role on the group.
     */
    private function guardGroup(Request $request): ?JsonResponse
    {
        $userId = $this->authService->currentUserId();

        if ($userId === null) {
            return $this->unauthenticated();
        }

        $groupId = (int) $request->attributes->get('groupId', 0);

        if (Group::find($groupId) === null) {
            return $this->notFound('GROUP_NOT_FOUND', 'Group not found.');
        }

        return $this->groupService::callerCanManage($groupId, (int) $userId, $this->authService->isAdmin())
            ? null
            : $this->forbidden('FORBIDDEN', self::MSG_CANNOT_MANAGE_GROUP);
    }

    /**
     * A global key is a global-admin right, and nothing else.
     */
    private function guardGlobal(): ?JsonResponse
    {
        $userId = $this->authService->currentUserId();

        if ($userId === null) {
            return $this->unauthenticated();
        }

        return $this->authService->isAdmin()
            ? null
            : $this->forbidden('FORBIDDEN', self::MSG_NOT_GLOBAL_ADMIN);
    }
}

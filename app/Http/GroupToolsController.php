<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Models\Group;
use Spora\Models\Principal;
use Spora\Models\ToolUserSetting;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET / POST / DELETE` for tool user settings bound to a group's
 * group-principal. Reuses {@see ToolConfigService} so encryption,
 * schema-driven filtering, and the password `***` sentinel flow work
 * identically to the per-user settings at `/api/v1/tools/{id}/user-settings`.
 *
 * Endpoints:
 *   GET    /api/v1/groups/{id}/tools
 *   POST   /api/v1/groups/{id}/tools/{toolClass}
 *   DELETE /api/v1/groups/{id}/tools/{toolClass}
 *
 * Authorisation: read uses `callerCanSeeGroup()` (members can list);
 * write uses `callerCanManageGroup()` (owner / admin / global admin).
 * Non-members receive 404 (existence-hiding, not 403).
 */
final class GroupToolsController
{
    use JsonControllerHelpers;
    use GroupAuthorizationTrait;

    private const MSG_INVALID_JSON = 'Request body must be valid JSON.';
    private const MSG_GROUP_NOT_FOUND = 'Group not found.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly PrincipalService $principalService,
        private readonly ToolConfigService $toolConfigService,
    ) {}

    public function index(int $id): JsonResponse
    {
        $resolved = $this->resolveReadableGroup($id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, ] = $resolved;

        $rows = ToolUserSetting::where('principal_id', $principalId)
            ->orderBy('tool_class')
            ->get();

        $settings = array_map(function (ToolUserSetting $row) use ($principalId): array {
            $decrypted = $this->toolConfigService->getPrincipalSettings(
                (string) $row->tool_class,
                $principalId,
            );
            $updatedAt = (string) $row->getRawOriginal('updated_at');
            return [
                'tool_class'   => (string) $row->tool_class,
                'principal_id' => (int) $row->principal_id,
                'settings'     => $this->toolConfigService->maskForApi(
                    $decrypted,
                    (string) $row->tool_class,
                ),
                'updated_at'   => $updatedAt,
            ];
        }, $rows->all());

        return new JsonResponse(['data' => ['tools' => $settings]]);
    }

    public function upsert(int $id, string $toolClass, Request $request): JsonResponse
    {
        $resolved = $this->resolveWritableGroup($id, 'Only group owners or admins can edit tool settings.');
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, ] = $resolved;

        $settings = $this->decodedSettingsOrFail($request);
        if ($settings instanceof JsonResponse) {
            return $settings;
        }

        return $this->applyToolSettings($toolClass, $principalId, $settings);
    }

    public function destroy(int $id, string $toolClass): JsonResponse
    {
        $resolved = $this->resolveWritableGroup($id, 'Only group owners or admins can delete tool settings.');
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, ] = $resolved;

        $this->toolConfigService->deletePrincipalSettings($toolClass, $principalId);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * @return array{0: int, 1: int}|JsonResponse
     */
    private function resolveReadableGroup(int $id): array|JsonResponse
    {
        $userId = $this->requireCurrentUserIdOrFail();
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        $principal = $this->loadGroupPrincipalIfVisible($id, $userId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }

        return [(int) $principal->id, $userId];
    }

    /**
     * @return array{0: int, 1: int}|JsonResponse
     */
    private function resolveWritableGroup(int $id, string $denyMessage): array|JsonResponse
    {
        $resolved = $this->resolveReadableGroup($id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, $userId] = $resolved;

        if (!$this->callerCanManageGroup($id, $userId, $this->authService)) {
            return $this->forbidden('FORBIDDEN', $denyMessage);
        }

        return [$principalId, $userId];
    }

    /**
     * @return int|JsonResponse
     */
    private function requireCurrentUserIdOrFail(): int|JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }
        return (int) $userId;
    }

    private function loadGroupPrincipalIfVisible(int $id, int $userId): Principal|JsonResponse
    {
        $group = Group::find($id);
        if ($group === null || !$this->callerCanSeeGroup($id, $userId)) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        $principal = $this->principalService->principalForGroup($id);
        if ($principal === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        return $principal;
    }

    private function callerCanSeeGroup(int $groupId, int $userId): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }
        return Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Decode + unwrap the settings payload from the request body. Pass
     * either `{settings: {…}}` or a bare object — both shapes are
     * accepted (see {@see extractSettings}).
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function decodedSettingsOrFail(Request $request): array|JsonResponse
    {
        $body = $this->decodeBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->extractSettings($body);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeBody(Request $request): array|JsonResponse
    {
        try {
            return $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Accepts either `{settings: {…}}` (the preferred shape) or the bare
     * settings object. Always returns an array so callers don't have to
     * distinguish "no body" from "empty settings".
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|JsonResponse
     */
    private function extractSettings(array $body): array|JsonResponse
    {
        if (array_key_exists('settings', $body)) {
            if (!is_array($body['settings'])) {
                return $this->unprocessable('VALIDATION_ERROR', 'settings must be an object.');
            }
            return $body['settings'];
        }

        // Bare object — accept it as the settings payload. `principal_id` is
        // explicitly ignored so the caller can't redirect the write to a
        // different principal (the principal is the group's, end of story).
        unset($body['principal_id']);
        return $body;
    }

    /**
     * Persist settings via ToolConfigService and return the masked wire
     * payload.
     *
     * @param array<string, mixed> $settings
     */
    private function applyToolSettings(string $toolClass, int $principalId, array $settings): JsonResponse
    {
        $this->toolConfigService->putPrincipalSettings($toolClass, $principalId, $settings);

        return new JsonResponse(['data' => [
            'tool' => [
                'tool_class'   => $toolClass,
                'principal_id' => $principalId,
                'settings'     => $this->toolConfigService->maskForApi(
                    $this->toolConfigService->getPrincipalSettings($toolClass, $principalId),
                    $toolClass,
                ),
            ],
        ]]);
    }
}

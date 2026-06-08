<?php

declare(strict_types=1);

namespace Spora\Http;

use Delight\Auth\Role;
use InvalidArgumentException;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Auth\Exceptions\EmailTakenException;
use Spora\Services\UserServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administrative user management: list, create, update, delete, and role grant/revoke.
 */
final class UserController
{
    use JsonControllerHelpers;
    private const ROLE_MAP = [
        'ADMIN'       => Role::ADMIN,
        'AUTHOR'      => Role::AUTHOR,
        'CONSULTANT'  => Role::CONSULTANT,
        'COORDINATOR' => Role::COORDINATOR,
        'MODERATOR'   => Role::MODERATOR,
    ];

    private const ERR_USER_NOT_FOUND = 'User not found.';

    private const ERR_INVALID_JSON = 'Request body must be valid JSON.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly UserServiceInterface $userService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) ($request->query->get('per_page', 20)), 100);
        $page    = max((int) ($request->query->get('page', 1)), 1);

        $result = $this->userService->getUsers($page, $perPage);

        return new JsonResponse([
            'data' => $result['data'],
            'meta' => $result['meta'],
        ], Response::HTTP_OK);
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->userService->getUser($id);

        if ($result === null) {
            return $this->error('NOT_FOUND', self::ERR_USER_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $result], Response::HTTP_OK);
    }

    public function store(Request $request): JsonResponse
    {
        $result = $this->registerUser($request);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return new JsonResponse(
            ['data' => $this->userService->getUser($result)],
            Response::HTTP_CREATED,
        );
    }

    private function registerUser(Request $request): JsonResponse|int
    {
        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::ERR_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }

        if ($this->missingFields($body, ['email', 'password'])) {
            return $this->error('VALIDATION_ERROR', 'The fields "email" and "password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->attemptRegister($body);
    }

    private function attemptRegister(array $body): JsonResponse|int
    {
        try {
            return $this->authService->register((string) $body['email'], (string) $body['password'], (string) $body['email']);
        } catch (EmailTakenException) {
            return $this->error('EMAIL_TAKEN', 'A user with that email address already exists.', Response::HTTP_CONFLICT);
        } catch (InvalidArgumentException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::ERR_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }

        $result = $this->userService->updateUser($id, $body);

        if ($result === null) {
            return $this->error('NOT_FOUND', self::ERR_USER_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $result], Response::HTTP_OK);
    }

    public function destroy(int $id): JsonResponse
    {
        $currentUserId = $this->authService->currentUserId();

        if ($currentUserId !== null && (int) $id === $currentUserId) {
            return $this->error('CANNOT_DELETE_SELF', 'You cannot delete your own account.', Response::HTTP_CONFLICT);
        }

        $deleted = $this->userService->deleteUser($id);

        if (!$deleted) {
            return $this->error('NOT_FOUND', self::ERR_USER_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    public function grantRole(Request $request, int $id): JsonResponse
    {
        $result = $this->grantRoleToUser($request, $id);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return new JsonResponse(['data' => $result], Response::HTTP_OK);
    }

    private function grantRoleToUser(Request $request, int $id): JsonResponse|array
    {
        $body = $this->decodeBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $roleValidation = $this->validateGrantRolePayload($body);
        if ($roleValidation instanceof JsonResponse) {
            return $roleValidation;
        }

        return $this->applyRoleGrant($id, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyRoleGrant(int $id, array $body): JsonResponse|array
    {
        $result = $this->userService->grantRole($id, (string) $body['role']);

        if ($result === null) {
            return $this->error('NOT_FOUND', self::ERR_USER_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeBodyOrFail(Request $request): array|JsonResponse
    {
        try {
            return $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::ERR_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }
    }

    private function validateGrantRolePayload(array $body): ?JsonResponse
    {
        if (empty($body['role'])) {
            return $this->error('VALIDATION_ERROR', 'The field "role" is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!isset(self::ROLE_MAP[strtoupper((string) $body['role'])])) {
            return $this->error('VALIDATION_ERROR', 'Invalid role.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return null;
    }

    public function revokeRole(int $id, string $role): JsonResponse
    {
        $roleName = $role;

        $roleValue = self::ROLE_MAP[strtoupper($roleName)] ?? null;

        if ($roleValue === null) {
            return $this->error('VALIDATION_ERROR', 'Invalid role.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->userService->revokeRole($id, $roleName);

        if ($result === null) {
            return $this->error('NOT_FOUND', self::ERR_USER_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $result], Response::HTTP_OK);
    }

    public function listRoles(int $id): JsonResponse
    {
        $user = \Spora\Models\User::find($id);

        if ($user === null) {
            return $this->error('NOT_FOUND', self::ERR_USER_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        $roles = $this->userService->listRoles($id);

        return new JsonResponse(
            ['data' => ['roles' => $roles]],
            Response::HTTP_OK,
        );
    }

    private function missingFields(array $body, array $fields): bool
    {
        foreach ($fields as $field) {
            if (($body[$field] ?? '') === '') {
                return true;
            }
        }

        return false;
    }
}

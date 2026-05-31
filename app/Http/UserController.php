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
    private const ROLE_MAP = [
        'ADMIN'       => Role::ADMIN,
        'AUTHOR'      => Role::AUTHOR,
        'CONSULTANT'  => Role::CONSULTANT,
        'COORDINATOR' => Role::COORDINATOR,
        'MODERATOR'   => Role::MODERATOR,
    ];

    public function __construct(
        private readonly AuthService $authService,
        private readonly UserServiceInterface $userService,
    ) {}

    public function index(Request $request, array $vars = []): JsonResponse
    {
        $perPage = min((int) ($request->query->get('per_page', 20)), 100);
        $page    = max((int) ($request->query->get('page', 1)), 1);

        $result = $this->userService->getUsers($page, $perPage);

        return new JsonResponse([
            'data' => $result['data'],
            'meta' => $result['meta'],
        ], Response::HTTP_OK);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $result = $this->userService->getUser($id);

        if ($result === null) {
            return $this->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $result], Response::HTTP_OK);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->missingFields($body, ['email', 'password'])) {
            return $this->error('VALIDATION_ERROR', 'The fields "email" and "password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $userId = $this->authService->register((string) $body['email'], (string) $body['password'], (string) $body['email']);
        } catch (EmailTakenException) {
            return $this->error('EMAIL_TAKEN', 'A user with that email address already exists.', Response::HTTP_CONFLICT);
        } catch (InvalidArgumentException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->userService->getUser($userId);

        return new JsonResponse(
            ['data' => $result],
            Response::HTTP_CREATED,
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->userService->updateUser($id, $body);

        if ($result === null) {
            return $this->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $result], Response::HTTP_OK);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $currentUserId = $this->authService->currentUserId();

        if ($currentUserId !== null && (int) $id === $currentUserId) {
            return $this->error('CANNOT_DELETE_SELF', 'You cannot delete your own account.', Response::HTTP_CONFLICT);
        }

        $deleted = $this->userService->deleteUser($id);

        if (!$deleted) {
            return $this->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    public function grantRole(Request $request, int $id): JsonResponse
    {
        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if (empty($body['role'])) {
            return $this->error('VALIDATION_ERROR', 'The field "role" is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $role = self::ROLE_MAP[strtoupper((string) $body['role'])] ?? null;

        if ($role === null) {
            return $this->error('VALIDATION_ERROR', 'Invalid role.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->userService->grantRole($id, (string) $body['role']);

        if ($result === null) {
            return $this->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $result], Response::HTTP_OK);
    }

    public function revokeRole(Request $request, int $id, string $role): JsonResponse
    {
        $roleName = $role;

        $roleValue = self::ROLE_MAP[strtoupper($roleName)] ?? null;

        if ($roleValue === null) {
            return $this->error('VALIDATION_ERROR', 'Invalid role.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->userService->revokeRole($id, $roleName);

        if ($result === null) {
            return $this->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $result], Response::HTTP_OK);
    }

    public function listRoles(Request $request, int $id): JsonResponse
    {
        $user = \Spora\Models\User::find($id);

        if ($user === null) {
            return $this->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
        }

        $roles = $this->userService->listRoles($id);

        return new JsonResponse(
            ['data' => ['roles' => $roles]],
            Response::HTTP_OK,
        );
    }

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
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

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}

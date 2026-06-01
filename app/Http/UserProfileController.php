<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Services\UserServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages user profiles and associated locations.
 */
final class UserProfileController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly UserServiceInterface $userService,
    ) {}

    public function getProfile(Request $request): JsonResponse
    {


        $userId = $this->authService->currentUserId();
        $result = $this->userService->getProfile($userId);

        if ($result === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'User not found.']], 404);
        }

        return new JsonResponse(['data' => $result['profile']]);
    }

    public function putProfile(Request $request): JsonResponse
    {


        $userId = $this->authService->currentUserId();

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $result = $this->userService->updateProfile($userId, $body);

        return new JsonResponse(['data' => $result['profile']]);
    }

    public function getLocations(Request $request): JsonResponse
    {


        $userId = $this->authService->currentUserId();
        $locations = $this->userService->getLocations($userId);

        return new JsonResponse(['data' => ['locations' => $locations]]);
    }

    public function postLocation(Request $request): JsonResponse
    {


        $userId = $this->authService->currentUserId();

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'name is required.']], 422);
        }

        $address = trim((string) ($body['address'] ?? ''));
        if ($address === '') {
            return new JsonResponse(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'address is required.']], 422);
        }

        $result = $this->userService->createLocation($userId, $body);

        return new JsonResponse(['data' => $result['location']], 201);
    }

    public function putLocation(Request $request, int $id): JsonResponse
    {


        $userId = $this->authService->currentUserId();

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return new JsonResponse(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'name is required and cannot be empty.']], 422);
            }
        }

        if (isset($body['address'])) {
            $address = trim((string) $body['address']);
            if ($address === '') {
                return new JsonResponse(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'address is required and cannot be empty.']], 422);
            }
        }

        $result = $this->userService->updateLocation($id, $userId, $body);

        if ($result === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'Location not found.']], 404);
        }

        return new JsonResponse(['data' => $result['location']]);
    }

    public function deleteLocation(Request $request, int $id): JsonResponse
    {


        $userId = $this->authService->currentUserId();
        $deleted = $this->userService->deleteLocation($id, $userId);

        if (!$deleted) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'Location not found.']], 404);
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}

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
    private const ERR_INVALID_JSON_MESSAGE = 'Request body must be valid JSON.';

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
                ['error' => ['code' => 'INVALID_JSON', 'message' => self::ERR_INVALID_JSON_MESSAGE]],
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
                ['error' => ['code' => 'INVALID_JSON', 'message' => self::ERR_INVALID_JSON_MESSAGE]],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $validationError = $this->validateLocationFields($body, true);
        if ($validationError !== null) {
            return $validationError;
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
                ['error' => ['code' => 'INVALID_JSON', 'message' => self::ERR_INVALID_JSON_MESSAGE]],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $validationError = $this->validateLocationFields($body, false);
        if ($validationError !== null) {
            return $validationError;
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

    /**
     * Validate name/address fields. Returns a JsonResponse on the first violation, or null if valid.
     *
     * @param bool $requireBoth when true (POST), both fields are required; when false (PUT), only present fields are checked
     */
    private function validateLocationFields(array $body, bool $requireBoth): ?JsonResponse
    {
        $result = $this->validateLocationField('name', $body, $requireBoth);
        if ($result !== null) {
            return $result;
        }

        return $this->validateLocationField('address', $body, $requireBoth);
    }

    /**
     * Validate a single location field. Returns a JsonResponse on violation, or null if valid.
     */
    private function validateLocationField(string $field, array $body, bool $requireBoth): ?JsonResponse
    {
        $isMissing = !isset($body[$field]);
        if ($isMissing) {
            return $requireBoth
                ? new JsonResponse(
                    ['error' => ['code' => 'VALIDATION_ERROR', 'message' => $field . ' is required.']],
                    422,
                )
                : null;
        }

        $value = trim((string) $body[$field]);
        if ($value !== '') {
            return null;
        }

        $message = $requireBoth
            ? $field . ' is required.'
            : $field . ' is required and cannot be empty.';
        return new JsonResponse(
            ['error' => ['code' => 'VALIDATION_ERROR', 'message' => $message]],
            422,
        );
    }
}

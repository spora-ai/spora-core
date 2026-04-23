<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Support\Carbon;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Spora\Models\User;
use Spora\Models\UserLocation;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class UserProfileController
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function getProfile(Request $request): JsonResponse
    {
        AuthGuard::requireAuth($this->authService);

        $userId = $this->authService->currentUserId();
        $user = User::find($userId);

        if ($user === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'User not found.']], 404);
        }

        return new JsonResponse(['data' => [
            'name'         => $user->name,
            'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
            'about_me'     => $user->about_me,
            'height_cm'    => $user->height_cm,
            'weight_kg'    => $user->weight_kg,
        ]]);
    }

    public function putProfile(Request $request): JsonResponse
    {
        AuthGuard::requireAuth($this->authService);

        $userId = $this->authService->currentUserId();
        $user = User::find($userId);

        if ($user === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'User not found.']], 404);
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (isset($body['name'])) {
            $user->name = trim((string) $body['name']) ?: null;
        }
        if (isset($body['date_of_birth'])) {
            $user->date_of_birth = $body['date_of_birth'] !== '' ? Carbon::parse($body['date_of_birth']) : null;
        }
        if (isset($body['about_me'])) {
            $user->about_me = trim((string) $body['about_me']) ?: null;
        }
        if (isset($body['height_cm'])) {
            $user->height_cm = $body['height_cm'] !== '' ? (float) $body['height_cm'] : null;
        }
        if (isset($body['weight_kg'])) {
            $user->weight_kg = $body['weight_kg'] !== '' ? (float) $body['weight_kg'] : null;
        }

        $user->save();

        return new JsonResponse(['data' => [
            'name'         => $user->name,
            'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
            'about_me'     => $user->about_me,
            'height_cm'    => $user->height_cm,
            'weight_kg'    => $user->weight_kg,
        ]]);
    }

    public function getLocations(Request $request): JsonResponse
    {
        AuthGuard::requireAuth($this->authService);

        $userId = $this->authService->currentUserId();
        $user = User::find($userId);

        if ($user === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'User not found.']], 404);
        }
        $locations = $user->locations ?? collect();

        $data = $locations->map(fn(UserLocation $loc) => [
            'id'        => $loc->id,
            'name'      => $loc->name,
            'address'   => $loc->address,
            'is_default' => $loc->is_default,
        ])->values()->toArray();

        return new JsonResponse(['data' => ['locations' => $data]]);
    }

    public function postLocation(Request $request): JsonResponse
    {
        AuthGuard::requireAuth($this->authService);

        $userId = $this->authService->currentUserId();
        if (User::find($userId) === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'User not found.']], 404);
        }

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

        if (($body['is_default'] ?? false) === true) {
            UserLocation::where('user_id', $userId)->update(['is_default' => false]);
        }

        $location = UserLocation::create([
            'user_id'    => $userId,
            'name'       => $name,
            'address'    => $address,
            'is_default' => ($body['is_default'] ?? false) === true,
        ]);

        return new JsonResponse(['data' => [
            'id'        => $location->id,
            'name'      => $location->name,
            'address'   => $location->address,
            'is_default' => $location->is_default,
        ]], 201);
    }

    public function putLocation(Request $request, int $id): JsonResponse
    {
        AuthGuard::requireAuth($this->authService);

        $userId = $this->authService->currentUserId();
        $location = UserLocation::where('id', $id)->where('user_id', $userId)->first();

        if ($location === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'Location not found.']], 404);
        }

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
            $location->name = $name;
        }
        if (isset($body['address'])) {
            $address = trim((string) $body['address']);
            if ($address === '') {
                return new JsonResponse(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'address is required and cannot be empty.']], 422);
            }
            $location->address = $address;
        }
        if (isset($body['is_default'])) {
            if ($body['is_default'] === true) {
                UserLocation::where('user_id', $userId)->where('id', '!=', $id)->update(['is_default' => false]);
            }
            $location->is_default = $body['is_default'] === true;
        }

        $location->save();

        return new JsonResponse(['data' => [
            'id'        => $location->id,
            'name'      => $location->name,
            'address'   => $location->address,
            'is_default' => $location->is_default,
        ]]);
    }

    public function deleteLocation(Request $request, int $id): JsonResponse
    {
        AuthGuard::requireAuth($this->authService);

        $userId = $this->authService->currentUserId();
        $location = UserLocation::where('id', $id)->where('user_id', $userId)->first();

        if ($location === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'Location not found.']], 404);
        }

        $location->delete();

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

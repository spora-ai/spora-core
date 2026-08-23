<?php

declare(strict_types=1);

namespace Spora\Http;

use InvalidArgumentException;
use Spora\Services\ProfilePictures\GroupPictureService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Validates the optional `profile_picture` JSON body field on
 * POST/PATCH `/api/v1/groups[/{id}]`. Extracted from
 * {@see GroupController} so the controller stays under the
 * SonarCloud S1448 20-method cap.
 *
 * Mirrors {@see AgentController::validateProfilePicturePayload()}
 * so the operator's PATCH body uses the same shape for both agents
 * and groups. The picture payload is validated *before* the
 * groups-row write so an invalid picture never partially overwrites
 * the name / description.
 *
 * Wire-format helpers (422 / 404 envelopes) come from
 * {@see JsonControllerHelpers} on the controller; pass them as
 * callables so the wire literals stay in one place.
 */
final class GroupProfilePictureValidator
{
    /**
     * @param  array<string, mixed>       $body
     * @param  GroupPictureService        $pictureService
     * @param  callable(string, string): JsonResponse $unprocessable 422 envelope factory
     * @return ?JsonResponse  null when the key is absent / well-formed;
     *                       a 422 envelope on any failure.
     */
    public static function validate(
        array $body,
        GroupPictureService $pictureService,
        callable $unprocessable,
    ): ?JsonResponse {
        if (!array_key_exists('profile_picture', $body)) {
            return null;
        }
        return self::validatePicture($body['profile_picture'], $pictureService, $unprocessable);
    }

    /**
     * @param  callable(string, string): JsonResponse $unprocessable
     */
    private static function validatePicture(
        mixed $picture,
        GroupPictureService $pictureService,
        callable $unprocessable,
    ): ?JsonResponse {
        if (!is_array($picture)) {
            return $unprocessable('PROFILE_PICTURE_TYPE', "Field 'profile_picture' must be a JSON object.");
        }
        $shapeError = self::shapeError($picture, $unprocessable);
        if ($shapeError !== null) {
            return $shapeError;
        }
        return self::enumError($picture, $pictureService, $unprocessable);
    }

    /**
     * @param  array<int|string, mixed> $picture
     * @param  callable(string, string): JsonResponse $unprocessable
     */
    private static function shapeError(array $picture, callable $unprocessable): ?JsonResponse
    {
        $allowed = ['archetype', 'variant_key', 'palette_key'];
        foreach (array_keys($picture) as $key) {
            if (!in_array($key, $allowed, true)) {
                return $unprocessable('PROFILE_PICTURE_UNKNOWN_KEY', "Unknown field 'profile_picture.{$key}'.");
            }
        }
        foreach ($allowed as $key) {
            if (array_key_exists($key, $picture) && !is_string($picture[$key])) {
                return $unprocessable('PROFILE_PICTURE_TYPE', "Field 'profile_picture.{$key}' must be a string.");
            }
        }
        return null;
    }

    /**
     * @param  array<int|string, mixed> $picture
     * @param  callable(string, string): JsonResponse $unprocessable
     */
    private static function enumError(
        array $picture,
        GroupPictureService $pictureService,
        callable $unprocessable,
    ): ?JsonResponse {
        try {
            if (isset($picture['archetype'])) {
                $pictureService->normaliseArchetype((string) $picture['archetype']);
            }
            if (isset($picture['variant_key'])) {
                $pictureService->normaliseVariantKey((string) $picture['variant_key']);
            }
            if (isset($picture['palette_key'])) {
                $pictureService->normalisePalette((string) $picture['palette_key']);
            }
        } catch (InvalidArgumentException $e) {
            return $unprocessable('PROFILE_PICTURE_VALUE', $e->getMessage());
        }
        return null;
    }
}

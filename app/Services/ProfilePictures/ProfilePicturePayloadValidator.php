<?php

declare(strict_types=1);

namespace Spora\Services\ProfilePictures;

use InvalidArgumentException;

/**
 * HTTP-free validator for the wire-level `profile_picture` object on
 * PATCH endpoints. Lives outside {@see ProfilePictureService} so the
 * service stays under the SonarCloud S1448 20-method cap (the
 * abstract subclass hooks push it close to the limit on their own).
 *
 * The controller-side call site is a one-liner that wraps a
 * {@see ProfilePictureValidationError} result in the standard 422
 * envelope — see {@see \Spora\Http\AgentController::update()} and
 * {@see \Spora\Http\GroupController::update()} for the wire contract.
 *
 * "Key absent" is NOT handled here — the controller short-circuits
 * before calling this class when the body's `profile_picture` key
 * is missing, since that case means "no avatar update". An explicit
 * `null` IS handled and produces a 422, because the caller asked us
 * to clear the picture and we can't clear what wasn't an object to
 * begin with.
 *
 * @internal used by the PATCH controllers; not part of the public
 *           service API surface.
 */
final class ProfilePicturePayloadValidator
{
    /**
     * Whitelisted keys of the wire-level `profile_picture` object.
     * Centralised so the type/shape/enum validators and the PATCH
     * writer agree on the same surface; adding a field here is a
     * one-line change.
     */
    public const PICTURE_PAYLOAD_KEYS = ['archetype', 'variant_key', 'palette_key'];

    /**
     * @param  ProfilePictureService $service needed for `normaliseArchetype` /
     *                                  `normaliseVariantKey` / `normalisePalette`
     *                                  enum validation (the validators call into
     *                                  the service for the canonical enum list).
     * @return array<string, string>|ProfilePictureValidationError
     */
    public static function validate(
        mixed $picture,
        ProfilePictureService $service,
    ): array|ProfilePictureValidationError {
        if (!is_array($picture)) {
            return new ProfilePictureValidationError(
                'PROFILE_PICTURE_TYPE',
                "Field 'profile_picture' must be a JSON object.",
            );
        }
        return self::validateShapeAndEnum($picture, $service);
    }

    /**
     * @param  array<int|string, mixed> $picture
     * @return array<string, string>|ProfilePictureValidationError
     */
    private static function validateShapeAndEnum(
        array $picture,
        ProfilePictureService $service,
    ): array|ProfilePictureValidationError {
        $shapeError = self::shapeError($picture);
        if ($shapeError !== null) {
            return $shapeError;
        }
        $enumError = self::enumError($picture, $service);
        if ($enumError !== null) {
            return $enumError;
        }
        /** @var array<string, string> $picture */
        return $picture;
    }

    /**
     * @param  array<int|string, mixed> $picture
     */
    private static function shapeError(array $picture): ?ProfilePictureValidationError
    {
        foreach (array_keys($picture) as $key) {
            if (!in_array($key, self::PICTURE_PAYLOAD_KEYS, true)) {
                return new ProfilePictureValidationError(
                    'PROFILE_PICTURE_UNKNOWN_KEY',
                    "Unknown field 'profile_picture.{$key}'.",
                );
            }
        }
        foreach (self::PICTURE_PAYLOAD_KEYS as $key) {
            if (array_key_exists($key, $picture) && !is_string($picture[$key])) {
                return new ProfilePictureValidationError(
                    'PROFILE_PICTURE_TYPE',
                    "Field 'profile_picture.{$key}' must be a string.",
                );
            }
        }
        return null;
    }

    /**
     * @param  array<int|string, mixed> $picture
     */
    private static function enumError(
        array $picture,
        ProfilePictureService $service,
    ): ?ProfilePictureValidationError {
        try {
            if (isset($picture['archetype'])) {
                $service->normaliseArchetype((string) $picture['archetype']);
            }
            if (isset($picture['variant_key'])) {
                $service->normaliseVariantKey((string) $picture['variant_key']);
            }
            if (isset($picture['palette_key'])) {
                $service->normalisePalette((string) $picture['palette_key']);
            }
        } catch (InvalidArgumentException $e) {
            return new ProfilePictureValidationError('PROFILE_PICTURE_VALUE', $e->getMessage());
        }
        return null;
    }
}

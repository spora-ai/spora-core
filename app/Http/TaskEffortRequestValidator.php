<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Drivers\EffortLevel;
use Spora\Http\Exceptions\EffortValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parses the `effort` field of a task request body.
 *
 * The field has three states and all three have to survive to the write:
 * absent leaves the choice to the configuration, an empty string asks for
 * the model default, and a level names one. The canonical scale is the
 * parser — a level nobody knows is refused here rather than reaching a
 * provider as a typo.
 *
 * Static because it is a pure function of the body: four surfaces read the
 * field and three of them have no room in their constructors for another
 * collaborator.
 */
final class TaskEffortRequestValidator
{
    /** The error code an unusable effort is answered with. */
    public const ERROR_CODE = 'INVALID_EFFORT';

    /**
     * @param  array<string, mixed> $body
     * @return string|null null when the key is absent, '' when the message
     *                     explicitly asks for the model default, otherwise the level
     * @throws EffortValidationException  When the value is not a known level
     */
    public static function parse(array $body): ?string
    {
        if (!array_key_exists('effort', $body)) {
            return null;
        }

        $value = $body['effort'];

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new EffortValidationException('effort must be a string.');
        }

        if ($value === '') {
            return '';
        }

        if (EffortLevel::tryFrom($value) === null) {
            throw new EffortValidationException(
                sprintf('effort must be one of: %s.', implode(', ', self::levels())),
            );
        }

        return $value;
    }

    /**
     * The 422 an unusable effort is answered with. One place, so the
     * surfaces that read the field cannot drift apart.
     */
    public static function errorResponse(EffortValidationException $e): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => self::ERROR_CODE, 'message' => $e->getMessage()]],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * @return list<string>
     */
    private static function levels(): array
    {
        return array_map(
            static fn(EffortLevel $level): string => $level->value,
            EffortLevel::cases(),
        );
    }
}

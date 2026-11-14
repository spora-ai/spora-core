<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Http\Exceptions\EffortValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parses the `effort` field of a task request body.
 *
 * The field has three states and all three have to survive to the write:
 * absent leaves the choice to the configuration, an empty string asks for
 * the model default, and a level names one.
 *
 * The parser checks the shape only — the value must be a string. Which
 * levels exist is a property of the agent's LLM configuration, not a fixed
 * vocabulary, so membership is {@see \Spora\Services\EffortService::assertLevel()}'s
 * job; it runs immediately after this on every surface.
 *
 * Static because it is a pure function of the body: four surfaces read the
 * field and three of them have no room in their constructors for another
 * collaborator.
 */
final class TaskEffortRequestValidator
{
    /**
     * @param  array<string, mixed> $body
     * @return string|null null when the body names no effort at all, '' when the
     *                     message explicitly asks for the model default, otherwise the level
     * @throws EffortValidationException  When the value is not a string
     */
    public static function parse(array $body): ?string
    {
        $value = $body['effort'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new EffortValidationException(
                'effort must be a string.',
                EffortValidationException::CODE_MALFORMED,
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
            ['error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()]],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

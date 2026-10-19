<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared JSON / error helpers for Spora's API controllers. Used by
 * AgentController, AgentToolController, AgentOverrideController,
 * UserController, ScheduledRunController, AuthController, and others.
 *
 * Centralises the decodeJson / error / notFound / forbidden patterns that
 * were previously copy-pasted into 11+ controller files (SonarQube
 * duplication-density issue introduced by AgentControllerJsonHelpers).
 */
trait JsonControllerHelpers
{
    private const MSG_AUTHENTICATION_REQUIRED = 'Authentication required.';

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        // Valid JSON is not the same as a JSON *object*: `"text"`, `42`,
        // `true` and `null` all decode without throwing, to a scalar. The
        // array return type then trips, and a TypeError is not a JsonException
        // so the caller's catch does not see it — the request dies as a 500
        // for a body the caller could easily have written by mistake.
        // A body that is not an object simply has no fields in it.
        return is_array($decoded) ? $decoded : [];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function safeDecodeJson(Request $request): array|JsonResponse
    {
        try {
            return $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * The standard 401 envelope. The wire-format `error.code` and
     * message literals are kept here in lockstep — adding new
     * consuming controllers that ask for the unified envelope will
     * stay in sync with the others by construction.
     */
    private function unauthenticated(): JsonResponse
    {
        return $this->error('UNAUTHENTICATED', self::MSG_AUTHENTICATION_REQUIRED, Response::HTTP_UNAUTHORIZED);
    }

    private function notFound(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            Response::HTTP_NOT_FOUND,
        );
    }

    private function forbidden(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            Response::HTTP_FORBIDDEN,
        );
    }

    private function unprocessable(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * A query parameter as a trimmed string, or null when absent or blank.
     */
    private function queryString(Request $request, string $name): ?string
    {
        $value = $request->query->get($name);

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A query parameter as an int, or null when absent or not numeric.
     */
    private function queryInt(Request $request, string $name): ?int
    {
        $value = $this->queryString($request, $name);

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    /**
     * Clamp an optional int into `[min, max]`, falling back to null — which
     * the services read as "not supplied, use your own default".
     */
    private function boundedInt(?int $value, int $min, int $max): ?int
    {
        if ($value === null) {
            return null;
        }

        return max($min, min($max, $value));
    }
}

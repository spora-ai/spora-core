<?php

declare(strict_types=1);

namespace Spora\Http;

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
    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
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
}

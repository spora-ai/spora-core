<?php

declare(strict_types=1);

namespace Spora\Http;

use InvalidArgumentException;
use Spora\Services\MediaArchive\MediaCapabilityMismatchException;
use Spora\Services\MediaArchive\TaskMediaCapabilityInterface;
use Spora\Services\TaskServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dispatches the continue-task flow: validates the request body, fails fast
 * when the task is missing, gates the call on media-capability, and runs
 * `continueTask`. Surfaces domain exceptions as the JSON error shapes the
 * operator API contract documents.
 *
 * Extracted from `TaskController` to keep the controller under the
 * SonarCloud S1448 method-count threshold (≤20 methods per class).
 */
final class ContinueTaskDispatcher
{
    private const ERR_TASK_NOT_FOUND = 'Task not found.';

    public function __construct(
        private readonly TaskServiceInterface $taskService,
        private readonly TaskMediaCapabilityInterface $mediaCapability,
    ) {}

    /**
     * Validate the request body and dispatch `continueTask`.
     *
     * @param array<string, mixed> $body Decoded request body.
     */
    public function handleContinue(int $taskId, int $userId, array $body): JsonResponse
    {
        $validation = $this->validateBody($body);
        if ($validation['result'] !== null) {
            return $validation['result'];
        }

        return $this->dispatch(
            $taskId,
            $userId,
            $validation['prompt'],
            $validation['additionalSteps'],
            $validation['mediaIds'],
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array{result: ?JsonResponse, prompt: ?string, additionalSteps: ?int, mediaIds: list<string>}
     */
    private function validateBody(array $body): array
    {
        $prompt = $body['prompt'] ?? null;
        if (!is_string($prompt) || trim($prompt) === '') {
            return [
                'result' => new JsonResponse(
                    ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'prompt is required and must be a non-empty string.']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                ),
                'prompt' => null,
                'additionalSteps' => null,
                'mediaIds' => [],
            ];
        }

        if (isset($body['additional_steps'])
            && (!is_int($body['additional_steps']) || $body['additional_steps'] < 1 || $body['additional_steps'] > 100)
        ) {
            return [
                'result' => new JsonResponse(
                    ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'additional_steps must be an integer between 1 and 100.']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                ),
                'prompt' => null,
                'additionalSteps' => null,
                'mediaIds' => [],
            ];
        }

        return [
            'result' => null,
            'prompt' => $prompt,
            'additionalSteps' => isset($body['additional_steps']) ? $body['additional_steps'] : null,
            'mediaIds' => $this->mediaCapability->parseMediaIds($body['media_ids'] ?? null),
        ];
    }

    private function dispatch(
        int $taskId,
        int $userId,
        string $prompt,
        ?int $additionalSteps,
        array $mediaIds,
    ): JsonResponse {
        $existing = $this->taskService->getTask($taskId, $userId);
        if ($existing === null) {
            return $this->notFoundResponse();
        }

        return $this->dispatchWithMediaCheck(
            $existing['agent_id'],
            $taskId,
            $userId,
            $prompt,
            $additionalSteps,
            $mediaIds,
        );
    }

    /**
     * @param list<string> $mediaIds
     */
    private function dispatchWithMediaCheck(
        int $agentId,
        int $taskId,
        int $userId,
        string $prompt,
        ?int $additionalSteps,
        array $mediaIds,
    ): JsonResponse {
        try {
            $this->mediaCapability->ensureMediaCapabilityCompatible($agentId, $mediaIds);
            $task = $this->taskService->continueTask($taskId, $userId, $prompt, $additionalSteps, $mediaIds);

            return new JsonResponse(['data' => ['task' => $task]], Response::HTTP_OK);
        } catch (MediaCapabilityMismatchException | InvalidArgumentException $e) {
            return $this->exceptionResponse($e);
        }
    }

    /**
     * @param MediaCapabilityMismatchException|InvalidArgumentException $e
     */
    private function exceptionResponse(
        MediaCapabilityMismatchException|InvalidArgumentException $e,
    ): JsonResponse {
        if ($e instanceof MediaCapabilityMismatchException) {
            return new JsonResponse(
                ['error' => ['code' => 'MEDIA_CAPABILITY_MISMATCH', 'message' => $e->getMessage()]],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($e->getMessage() === self::ERR_TASK_NOT_FOUND) {
            return $this->notFoundResponse();
        }

        return new JsonResponse(
            ['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]],
            Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}

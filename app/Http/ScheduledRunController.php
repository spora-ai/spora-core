<?php

declare(strict_types=1);

namespace Spora\Http;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Services\ScheduledRunServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Manages scheduled runs: list, create, update, delete, and trigger agent tasks on a cron schedule.
 */
final class ScheduledRunController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $authService,
        private readonly ScheduledRunServiceInterface $scheduledRunService,
    ) {}

    /**
     * GET /api/v1/agents/{agentId}/scheduled-runs
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $runs = $this->scheduledRunService->getRunsForAgent($agentId, $userId);

        if ($runs === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['scheduled_runs' => $runs]]);
    }

    /**
     * POST /api/v1/agents/{agentId}/scheduled-runs
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $body = $this->decodeBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $validationError = $this->validateCreate($body);
        if ($validationError !== null) {
            return $validationError;
        }

        return $this->createRunAndRespond($agentId, $userId, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createRunAndRespond(int $agentId, ?int $userId, array $body): JsonResponse
    {
        try {
            $result = $this->scheduledRunService->createRun($agentId, $userId, $body);
            return new JsonResponse(
                ['data' => $result],
                Response::HTTP_CREATED,
            );
        } catch (RuntimeException) {
            return $this->notFound();
        }
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeBodyOrFail(Request $request): array|JsonResponse
    {
        try {
            return $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * GET /api/v1/agents/{agentId}/scheduled-runs/{runId}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);
        $runId = (int) $request->attributes->get('runId', 0);

        $result = $this->scheduledRunService->getRun($runId, $agentId, $userId);

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * PUT /api/v1/agents/{agentId}/scheduled-runs/{runId}
     */
    public function update(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);
        $runId = (int) $request->attributes->get('runId', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->scheduledRunService->updateRun($runId, $agentId, $userId, $body);

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * DELETE /api/v1/agents/{agentId}/scheduled-runs/{runId}
     */
    public function destroy(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);
        $runId = (int) $request->attributes->get('runId', 0);

        $deleted = $this->scheduledRunService->deleteRun($runId, $agentId, $userId);

        if (!$deleted) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * POST /api/v1/agents/{agentId}/scheduled-runs/{runId}/trigger
     *
     * Immediately creates a task from this scheduled run (one-shot deactivation afterwards).
     */
    public function trigger(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);
        $runId = (int) $request->attributes->get('runId', 0);

        try {
            $result = $this->scheduledRunService->triggerRun($runId, $agentId, $userId);
            return new JsonResponse(['data' => $result]);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return $this->notFound();
            }
            return $this->error(
                'ORCHESTRATOR_ERROR',
                'Failed to start task: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * Validate create payload.
     * Returns an error JsonResponse or null if valid.
     */
    private function validateCreate(array $body): ?JsonResponse
    {
        $flags = $this->classifyCreatePayload($body);

        $shapeError = $this->validatePayloadShape($flags['isRecurring'], $flags['isOneShot'], $flags['hasTemplate'], $flags['hasRawPrompt']);
        if ($shapeError !== null) {
            return $shapeError;
        }

        return $this->validateCreateSchedule($body, $flags['isOneShot'], $flags['isRecurring']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{isRecurring: bool, isOneShot: bool, hasTemplate: bool, hasRawPrompt: bool}
     */
    private function classifyCreatePayload(array $body): array
    {
        return [
            'isRecurring'  => !empty($body['cron_expression']),
            'isOneShot'    => !empty($body['run_at']),
            'hasTemplate'  => isset($body['template_id']) && is_int($body['template_id']),
            'hasRawPrompt' => isset($body['raw_prompt']) && trim((string) $body['raw_prompt']) !== '',
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validateCreateSchedule(array $body, bool $isOneShot, bool $isRecurring): ?JsonResponse
    {
        $tzError = $this->validateTimezone($body['timezone'] ?? null);
        if ($tzError !== null) {
            return $tzError;
        }
        $timezone = $this->resolveTimezone($body['timezone'] ?? null);

        if ($isOneShot) {
            $dateError = $this->validateOneShotDate($body['run_at'], $timezone);
            if ($dateError !== null) {
                return $dateError;
            }
        }

        if ($isRecurring) {
            return $this->validateCronExpression($body['cron_expression']);
        }

        return null;
    }

    private function validateTimezone(mixed $timezone): ?JsonResponse
    {
        if ($timezone === null) {
            return null; // omitted → defaults to 'UTC' in the service
        }
        if (!is_string($timezone)) {
            return $this->error(
                'VALIDATION_ERROR',
                'timezone must be a string (IANA identifier, e.g. "Europe/Berlin").',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        if (strlen($timezone) > 50) {
            return $this->error(
                'VALIDATION_ERROR',
                'timezone must not exceed 50 characters.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            return $this->error(
                'VALIDATION_ERROR',
                'timezone must be a valid IANA identifier (e.g. "UTC", "Europe/Berlin").',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        return null;
    }

    private function resolveTimezone(mixed $timezone): string
    {
        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    private function validatePayloadShape(bool $isRecurring, bool $isOneShot, bool $hasTemplate, bool $hasRawPrompt): ?JsonResponse
    {
        return match (true) {
            !$hasTemplate && !$hasRawPrompt => $this->error(
                'VALIDATION_ERROR',
                'Either template_id or raw_prompt is required.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ),
            $isRecurring && $isOneShot => $this->error(
                'VALIDATION_ERROR',
                'cron_expression and run_at are mutually exclusive.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ),
            !$isRecurring && !$isOneShot => $this->error(
                'VALIDATION_ERROR',
                'Either cron_expression (recurring) or run_at (one-shot) is required.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ),
            default => null,
        };
    }

    private function validateOneShotDate(mixed $runAt, string $timezone = 'UTC'): ?JsonResponse
    {
        if ($this->parseDateTime((string) $runAt, $timezone) === false) {
            return $this->error(
                'VALIDATION_ERROR',
                'run_at must be a valid ISO 8601 datetime.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return null;
    }

    private function validateCronExpression(mixed $cronExpression): ?JsonResponse
    {
        try {
            new CronExpression((string) $cronExpression);
        } catch (Throwable) {
            return $this->error(
                'VALIDATION_ERROR',
                'cron_expression is invalid.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return null;
    }

    private function parseDateTime(string $value, string $timezone = 'UTC'): DateTimeImmutable|false
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone($timezone));
        } catch (Throwable) {
            return false;
        }
    }

    private function notFound(): JsonResponse
    {
        // Override to provide a domain-specific error code; delegates to the
        // shared trait's response shape.
        return new JsonResponse(
            ['error' => ['code' => 'SCHEDULED_RUN_NOT_FOUND', 'message' => 'Scheduled run not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}

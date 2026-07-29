<?php

declare(strict_types=1);

namespace Spora\Http;

use InvalidArgumentException;
use Spora\Agents\SchemaValidator;
use Spora\Services\TaskServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parses and validates the `{decisions: [...]}` payload for
 * `POST /api/v1/tasks/{taskId}/approve`.
 *
 * Each entry is shaped as
 * `{provider_call_id: string, decision: 'approve'|'reject', arguments?: array, reason?: string}`.
 * Approved entries require `arguments` (validated against the tool's JSON Schema);
 * rejected entries accept an optional `reason` (defaults to "User rejected").
 *
 * Returns either the parsed list of decisions or a JsonResponse carrying
 * the first validation error encountered.
 */
final class DecisionsRequestValidator
{
    private const ERR_DECISIONS_LIST = 'decisions must be a non-empty array.';

    private const ERR_DECISION_SHAPE = 'Every decision must be an object.';

    private const ERR_PROVIDER_CALL_ID_REQUIRED = 'provider_call_id is required in every decision.';

    private const ERR_DECISION_CHOICE = "decision must be either 'approve' or 'reject'.";

    private const ERR_APPROVE_NEEDS_ARGUMENTS = 'arguments is required for approve decisions.';

    private const ERR_REJECT_REASON_TYPE = 'reason must be a string.';

    private const DEFAULT_REASON = 'User rejected';

    public function __construct(
        private readonly TaskServiceInterface $taskService,
    ) {}

    /**
     * @return list<array{provider_call_id: string, decision: 'approve'|'reject', arguments?: array<string, mixed>, reason?: string}>|JsonResponse
     */
    public function parseAndValidate(array $body, int $taskId, int $userId): array|JsonResponse
    {
        $parsed = $this->parseDecisionsList($body);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }

        $task = $this->taskService->getTaskWithHistory($taskId, $userId);
        if ($task === null) {
            return new JsonResponse(
                ['error' => ['code' => 'TASK_NOT_FOUND', 'message' => 'Task not found.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        return $this->validateAgainstTask($parsed, $task);
    }

    /**
     * @return list<array{provider_call_id: string, decision: 'approve'|'reject', arguments?: array<string, mixed>, reason?: string}>|JsonResponse
     */
    private function parseDecisionsList(array $body): array|JsonResponse
    {
        $raw = $body['decisions'] ?? null;
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            return $this->error(self::ERR_DECISIONS_LIST);
        }

        $decisions = [];
        foreach ($raw as $item) {
            $parsed = $this->parseSingleDecision($item);
            if ($parsed instanceof JsonResponse) {
                return $parsed;
            }
            $decisions[] = $parsed;
        }
        return $decisions;
    }

    /**
     * @return array{provider_call_id: string, decision: 'approve'|'reject', arguments?: array<string, mixed>, reason?: string}|JsonResponse
     */
    private function parseSingleDecision(mixed $item): array|JsonResponse
    {
        if (!is_array($item)) {
            return $this->error(self::ERR_DECISION_SHAPE);
        }
        $providerCallId = trim((string) ($item['provider_call_id'] ?? ''));
        if ($providerCallId === '') {
            return $this->error(self::ERR_PROVIDER_CALL_ID_REQUIRED);
        }
        return match ($item['decision'] ?? null) {
            'approve' => $this->parseApprovedDecision($item, $providerCallId),
            'reject'  => $this->parseRejectedDecision($item, $providerCallId),
            default   => $this->error(self::ERR_DECISION_CHOICE),
        };
    }

    /**
     * @return array{provider_call_id: string, decision: 'approve', arguments: array<string, mixed>}|JsonResponse
     */
    private function parseApprovedDecision(array $item, string $providerCallId): array|JsonResponse
    {
        $arguments = $item['arguments'] ?? null;
        if (!is_array($arguments)) {
            return $this->error(self::ERR_APPROVE_NEEDS_ARGUMENTS);
        }
        return [
            'provider_call_id' => $providerCallId,
            'decision'         => 'approve',
            'arguments'        => $arguments,
        ];
    }

    /**
     * @return array{provider_call_id: string, decision: 'reject', reason: string}|JsonResponse
     */
    private function parseRejectedDecision(array $item, string $providerCallId): array|JsonResponse
    {
        $reason = $item['reason'] ?? self::DEFAULT_REASON;
        if (!is_string($reason)) {
            return $this->error(self::ERR_REJECT_REASON_TYPE);
        }
        $reason = trim($reason);
        return [
            'provider_call_id' => $providerCallId,
            'decision'         => 'reject',
            'reason'           => $reason === '' ? self::DEFAULT_REASON : $reason,
        ];
    }

    /**
     * @param list<array{provider_call_id: string, decision: 'approve'|'reject', arguments?: array<string, mixed>, reason?: string}> $decisions
     * @param array<string, mixed> $task
     * @return list<array{provider_call_id: string, decision: 'approve'|'reject', arguments?: array<string, mixed>, reason?: string}>|JsonResponse
     */
    private function validateAgainstTask(array $decisions, array $task): array|JsonResponse
    {
        $toolCalls = $this->indexToolCallsByProviderCallId($task);
        foreach ($decisions as $decision) {
            $error = $this->validateDecisionAgainstToolCalls($decision, $toolCalls);
            if ($error !== null) {
                return $error;
            }
        }
        return $decisions;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, array<string, mixed>>
     */
    private function indexToolCallsByProviderCallId(array $task): array
    {
        $indexed = [];
        foreach ($task['tool_calls'] ?? [] as $toolCall) {
            $providerCallId = $toolCall['provider_call_id'] ?? null;
            if (is_string($providerCallId)) {
                $indexed[$providerCallId] = $toolCall;
            }
        }
        return $indexed;
    }

    /**
     * @param array{provider_call_id: string, decision: 'approve'|'reject', arguments?: array<string, mixed>, reason?: string} $decision
     * @param array<string, array<string, mixed>> $toolCalls
     */
    private function validateDecisionAgainstToolCalls(array $decision, array $toolCalls): ?JsonResponse
    {
        $toolCall = $toolCalls[$decision['provider_call_id']] ?? null;
        if ($toolCall === null) {
            return $this->error("provider_call_id '{$decision['provider_call_id']}' is not pending approval.");
        }
        if ($decision['decision'] !== 'approve') {
            return null;
        }
        return $this->validateApprovedArguments($decision['arguments'] ?? [], $toolCall);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $toolCall
     */
    private function validateApprovedArguments(array $arguments, array $toolCall): ?JsonResponse
    {
        $schema = is_array($toolCall['parameter_schema'] ?? null) ? $toolCall['parameter_schema'] : [];
        $operation = $toolCall['operation'] ?? null;
        $resolvedOperation = is_string($operation) && $operation !== '' ? $operation : null;
        try {
            SchemaValidator::validate($arguments, $schema, $resolvedOperation);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
        return null;
    }

    private function error(string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'VALIDATION_ERROR', 'message' => $message]],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

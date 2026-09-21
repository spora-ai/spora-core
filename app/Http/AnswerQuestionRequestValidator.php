<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Services\PrincipalResolver;
use Spora\Tools\PendingQuestion;
use Spora\Tools\PendingQuestionBatch;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parses and validates the `{tool_call_id, answers: [...]}` payload for
 * `POST /api/v1/tasks/{taskId}/answer`.
 *
 * Each `answers[]` entry is shaped as `{header, selections: string[], free_text?: string|null}`.
 * The headers must match every pending question in the batch referenced
 * by `tool_call_id`; selections must be a subset of the question's
 * `options[].label`; `free_text` is rejected when the question has
 * `allowFreeText=false`.
 *
 * Returns a normalised array suitable for the controller to write
 * (one tool row per batch) or a JsonResponse carrying the first
 * validation error encountered.
 */
final class AnswerQuestionRequestValidator
{
    private const ERR_TOOL_CALL_ID_REQUIRED = 'tool_call_id is required.';

    public function __construct(
        private readonly PrincipalResolver $principalResolver,
        private readonly AnswerPayloadParser $payloadParser = new AnswerPayloadParser(),
    ) {}

    /**
     * @param array<string, mixed> $body
     * @return array{batch: PendingQuestionBatch, formatted: string, byHeader: array<string, array{selections: list<string>, free_text: ?string}>}|JsonResponse
     */
    public function parseAndValidate(array $body, int $taskId, int $userId): array|JsonResponse
    {
        $inputs = $this->extractValidatedInputs($body, $taskId, $userId);
        if ($inputs instanceof JsonResponse) {
            return $inputs;
        }
        return $this->validateAndBuildResult($inputs);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{task: \Spora\Models\Task, tool_call_id: string, by_header: array<string, array{selections: list<string>, free_text: ?string}>}|JsonResponse
     */
    private function extractValidatedInputs(array $body, int $taskId, int $userId): array|JsonResponse
    {
        $toolCallId = trim((string) ($body['tool_call_id'] ?? ''));
        if ($toolCallId === '') {
            return $this->error(self::ERR_TOOL_CALL_ID_REQUIRED);
        }
        return $this->collectInputs($body, $toolCallId, $taskId, $userId);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{task: \Spora\Models\Task, tool_call_id: string, by_header: array<string, array{selections: list<string>, free_text: ?string}>}|JsonResponse
     */
    private function collectInputs(array $body, string $toolCallId, int $taskId, int $userId): array|JsonResponse
    {
        $byHeader = $this->payloadParser->extractAnswerMap($body);
        if ($byHeader instanceof JsonResponse) {
            return $byHeader;
        }
        $task = $this->loadTaskForUser($taskId, $userId);
        if ($task === null) {
            return $this->notFound();
        }
        return ['task' => $task, 'tool_call_id' => $toolCallId, 'by_header' => $byHeader];
    }

    /**
     * @param array{task: \Spora\Models\Task, tool_call_id: string, by_header: array<string, array{selections: list<string>, free_text: ?string}>} $inputs
     * @return array{batch: PendingQuestionBatch, formatted: string, byHeader: array<string, array{selections: list<string>, free_text: ?string}>}|JsonResponse
     */
    private function validateAndBuildResult(array $inputs): array|JsonResponse
    {
        $batch = $this->resolvePendingBatch($inputs['task'], $inputs['tool_call_id']);
        if ($batch instanceof JsonResponse) {
            return $batch;
        }
        return $this->buildFormattedResult($batch, $inputs['by_header']);
    }

    private function resolvePendingBatch(\Spora\Models\Task $task, string $toolCallId): PendingQuestionBatch|JsonResponse
    {
        $stateError = $this->ensureAwaitingInput($task);
        if ($stateError !== null) {
            return $stateError;
        }
        $state = $this->loadAgentState($task);
        if ($state === null) {
            return $this->error('Malformed pending_state on this task.');
        }
        return $this->findBatchOrError($state, $toolCallId);
    }

    private function ensureAwaitingInput(\Spora\Models\Task $task): ?JsonResponse
    {
        if ($task->status !== 'AWAITING_INPUT') {
            return $this->error('Task is not awaiting input.');
        }
        if (!is_string($task->pending_state) || $task->pending_state === '') {
            return $this->error('No pending question batch on this task.');
        }
        return null;
    }

    private function loadAgentState(\Spora\Models\Task $task): ?AgentState
    {
        try {
            return AgentState::fromJson($task->pending_state);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function findBatchOrError(AgentState $state, string $toolCallId): PendingQuestionBatch|JsonResponse
    {
        foreach ($state->pendingQuestions as $candidate) {
            if ($candidate->toolCallId === $toolCallId) {
                return $candidate;
            }
        }
        return $this->error("No pending question batch with tool_call_id '{$toolCallId}'.");
    }

    /**
     * @param array<string, array{selections: list<string>, free_text: ?string}> $byHeader
     * @return array{batch: PendingQuestionBatch, formatted: string, byHeader: array<string, array{selections: list<string>, free_text: ?string}>}|JsonResponse
     */
    private function buildFormattedResult(PendingQuestionBatch $batch, array $byHeader): array|JsonResponse
    {
        if (count($byHeader) !== count($batch->questions)) {
            return $this->error(sprintf(
                'Expected %d answer(s), got %d.',
                count($batch->questions),
                count($byHeader),
            ));
        }
        $formatted = [];
        foreach ($batch->questions as $question) {
            $line = $this->formatOneAnswer($question, $byHeader);
            if ($line instanceof JsonResponse) {
                return $line;
            }
            $formatted[] = $line;
        }
        return [
            'batch'     => $batch,
            'formatted' => implode("\n", $formatted),
            'byHeader'  => $byHeader,
        ];
    }

    private function formatOneAnswer(PendingQuestion $question, array $byHeader): string|JsonResponse
    {
        $answer = $byHeader[$question->header] ?? null;
        if ($answer === null) {
            return $this->error("Missing answer for question: {$question->header}.");
        }
        $validation = $this->validateAnswerAgainstQuestion($answer, $question);
        if ($validation !== null) {
            return $validation;
        }
        return $this->renderAnswerLine($answer['selections'], $answer['free_text']);
    }

    /**
     * @param array{selections: list<string>, free_text: ?string} $answer
     */
    private function validateAnswerAgainstQuestion(array $answer, PendingQuestion $question): ?JsonResponse
    {
        $labels = $this->optionLabels($question);
        foreach ($answer['selections'] as $sel) {
            if (!in_array($sel, $labels, true)) {
                return $this->error("Unknown option '{$sel}' for question '{$question->header}'.");
            }
        }
        if ($answer['free_text'] !== null && !$question->allowFreeText) {
            return $this->error("Question '{$question->header}' does not allow free-text answers.");
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private function optionLabels(PendingQuestion $question): array
    {
        $labels = [];
        foreach ($question->options as $option) {
            if (is_array($option) && isset($option['label'])) {
                $labels[] = (string) $option['label'];
            }
        }
        return $labels;
    }

    /**
     * @param list<string> $selections
     */
    private function renderAnswerLine(array $selections, ?string $freeText): string
    {
        $selectionsLiteral = '[' . implode(', ', array_map(static fn(string $s): string => '"' . $s . '"', $selections)) . ']';
        $freeTextPart = $freeText === null ? '' : ' free_text: "' . $freeText . '"';
        return "[ask_user_question selections: {$selectionsLiteral}{$freeTextPart}]";
    }

    private function loadTaskForUser(int $taskId, int $userId): ?\Spora\Models\Task
    {
        $visiblePrincipalIds = $this->principalResolver->visiblePrincipalIds($userId);
        $row = Capsule::table('tasks')
            ->where('id', $taskId)
            ->whereIn('principal_id', $visiblePrincipalIds)
            ->first();
        return $row === null ? null : \Spora\Models\Task::find($row->id);
    }

    private function error(string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'VALIDATION_ERROR', 'message' => $message]],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}

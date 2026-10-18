<?php

declare(strict_types=1);

namespace Spora\Services\AgentApi;

use Spora\Agents\TaskLifecyclePolicy;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall;
use Spora\Services\TaskHistorySerializer;
use Throwable;

/**
 * The wire shapes the Agent API projects a chat into.
 *
 * Three of them, and the difference between them is the point of the
 * feature: `/status` is a status and an answer, `/messages` is the human
 * side of the conversation, and `/transcript` is everything the admin UI
 * sees. Tool calls and thinking live in the last one only, so an
 * integrator polling for an answer never pays for them.
 */
final class AgentApiChatProjection
{
    /**
     * Statuses that mean the chat can no longer progress without the
     * caller. `AWAITING_SUB_AGENTS` is deliberately absent: it resolves on
     * its own once the sub-task finishes, so treating it as blocked would
     * report a stall that is not one.
     */
    public const BLOCKED_STATUSES = ['PENDING_APPROVAL', 'AWAITING_INPUT'];

    /**
     * @return array<string, mixed>
     */
    public function status(Task $task): array
    {
        return [
            'id' => (int) $task->id,
            'agent_id' => (int) $task->agent_id,
            'status' => (string) $task->status,
            'answer' => $this->answer($task),
            'error' => $this->error($task),
            'blocked' => $this->blocked($task),
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function messages(int $chatId, ?int $sinceSequence, ?string $appUrl): array
    {
        $query = TaskHistory::where('task_id', $chatId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('sequence');

        if ($sinceSequence !== null) {
            $query->where('sequence', '>', $sinceSequence);
        }

        $messages = [];

        foreach ($query->get() as $row) {
            $text = $row->content;

            if ($row->role === 'assistant' && ($text === null || $text === '')) {
                // An assistant turn that only carried tool calls has no
                // prose; it belongs to the transcript, not the conversation.
                continue;
            }

            $messages[] = [
                'sequence' => (int) $row->sequence,
                'role' => (string) $row->role,
                'text' => $text,
                'attachments' => $this->attachments($row, $appUrl),
                'at' => $row->created_at?->toIso8601String(),
            ];
        }

        return $messages;
    }

    /**
     * @return list<array{media_id: string, kind: string, url?: string}>|null
     */
    private function attachments(TaskHistory $row, ?string $appUrl): ?array
    {
        $sanitized = TaskHistorySerializer::sanitizeAttachmentsForApi($row->attachments);

        if ($sanitized === null || $sanitized === []) {
            return null;
        }

        $out = [];
        $base = $appUrl === null ? '' : rtrim($appUrl, '/');

        foreach ($sanitized as $attachment) {
            $entry = [
                'media_id' => $attachment['media_id'],
                'kind' => $attachment['kind'],
            ];
            $entry['url'] = $base . '/api/v1/agent-api/media/' . rawurlencode($attachment['media_id']);
            $out[] = $entry;
        }

        return $out;
    }

    private function answer(Task $task): ?string
    {
        $final = $task->final_response;

        if ($final !== null && $final !== '') {
            return $final;
        }

        // A chat that failed or was aborted mid-loop has no
        // `final_response`; the last assistant turn is the closest thing to
        // what it produced.
        $last = TaskHistory::where('task_id', $task->id)
            ->where('role', 'assistant')
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->orderByDesc('sequence')
            ->value('content');

        return is_string($last) && $last !== '' ? $last : null;
    }

    /**
     * @return array{code: string, message: string}|null
     */
    private function error(Task $task): ?array
    {
        if ($task->status !== 'FAILED') {
            return null;
        }

        return [
            'code' => (string) ($task->error_code ?? 'UNKNOWN'),
            'message' => (string) ($task->error_message ?? 'The chat failed.'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function blocked(Task $task): ?array
    {
        if (!in_array((string) $task->status, self::BLOCKED_STATUSES, true)) {
            return null;
        }

        if ($task->status === 'PENDING_APPROVAL') {
            return [
                'reason' => 'PENDING_APPROVAL',
                'tool_calls' => $this->pendingToolCalls((int) $task->id),
            ];
        }

        return [
            'reason' => 'AWAITING_INPUT',
            'questions' => $this->pendingQuestions($task),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingToolCalls(int $taskId): array
    {
        return ToolCall::where('task_id', $taskId)
            ->whereIn('status', ['PENDING', 'PENDING_APPROVAL'])
            ->orderBy('id')
            ->get()
            ->map(static fn(ToolCall $call): array => [
                'id' => (int) $call->id,
                'name' => (string) $call->tool_name,
                'operation' => $call->operation,
                'description' => $call->human_description,
                'arguments' => $call->proposed_arguments,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingQuestions(Task $task): array
    {
        if (!is_string($task->pending_state) || $task->pending_state === '') {
            return [];
        }

        try {
            $state = AgentState::fromJson($task->pending_state);
        } catch (Throwable) {
            return [];
        }

        if ($state->pendingQuestions === []) {
            return [];
        }

        return array_map(static fn($batch): array => $batch->toArray(), $state->pendingQuestions);
    }

    /**
     * Whether a chat has settled: terminal, or parked on something only
     * the caller can clear. Drives both the callback trigger and the
     * `blocked` payload, so the two cannot disagree.
     */
    public function hasSettled(Task $task): bool
    {
        $policy = new TaskLifecyclePolicy();
        $status = (string) $task->status;

        return $policy->isTerminal($status)
            || $status === 'ABORTED'
            || in_array($status, self::BLOCKED_STATUSES, true);
    }
}

<?php

declare(strict_types=1);

namespace Spora\Services\AgentApi;

use Illuminate\Database\Capsule\Manager as DB;
use InvalidArgumentException;
use Spora\Models\MediaAsset;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\TaskHistorySerializer;
use Spora\Services\TaskServiceInterface;
use Spora\Services\ToolCallSerializer;

/**
 * Scopes an Agent API request to the one agent its key belongs to.
 *
 * The *agent scope* is decided here rather than by the user-scoped
 * `TaskServiceInterface` methods, whose visibility comes from
 * `visiblePrincipalIds()`: a principal is shared by every group member,
 * which is the right rule for the admin UI and far too wide for a
 * credential. A key may only ever reach its own agent's chats.
 */
final class AgentApiScope
{
    /**
     * The error vocabulary, public because the controllers answer with these
     * strings and the OpenAPI annotations describe them — three places that
     * have to keep agreeing, and cannot when each holds its own copy.
     */
    public const ERR_NOT_FOUND = 'Chat not found.';

    public const ERR_NO_SUCH_CHAT = 'No such chat on this agent.';

    /** Sub-agent nesting is shallow; this is a cycle guard, not a real limit. */
    private const MAX_ANCESTOR_WALK = 16;

    private const DEFAULT_PER_PAGE = 25;

    /** Above this the per-row projection cost makes a page expensive. */
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly TaskServiceInterface $taskService,
        private readonly MediaArchiveService $mediaArchive,
        private readonly ToolCallSerializer $toolCallSerializer,
    ) {}

    /**
     * The transcript payload: the `taskDetailResource` shape minus the retry
     * and abort bookkeeping, with nulls where that resource omits a key.
     * Kept separate rather than delegating, because this surface has no retry
     * policy to report — and because it carries `pending_questions`, which is
     * what a parked chat's questions arrive on.
     *
     * Throws rather than returning null when the chat is not this agent's —
     * every caller needs a 404 for that and only for that, and a nullable
     * return would make "missing chat" indistinguishable from "no history".
     *
     * @return array<string, mixed>
     */
    public function transcript(int $chatId, int $agentId, ?int $sinceSequence): array
    {
        $task = $this->requireTask($chatId, $agentId);

        return [
            'id' => (int) $task->id,
            'agent_id' => (int) $task->agent_id,
            'status' => (string) $task->status,
            'user_prompt' => (string) $task->user_prompt,
            'final_response' => $task->final_response,
            'step_count' => (int) $task->step_count,
            'max_steps' => (int) $task->max_steps,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'parent_task_id' => $task->parent_task_id !== null ? (int) $task->parent_task_id : null,
            'error_code' => $task->error_code,
            'error_message' => $task->error_message,
            'tool_calls' => $this->toolCalls((int) $task->id),
            'history' => $this->history((int) $task->id, $sinceSequence),
            'totals' => $this->totals((int) $task->id),
            'data' => $task->data,
        ];
    }

    /**
     * The chat, when it belongs to `$agentId`.
     *
     * @throws InvalidArgumentException when it does not — callers tell that
     *     apart from a wrong-state 409 with {@see self::chatMissing()}.
     */
    public function requireTask(int $chatId, int $agentId): Task
    {
        $task = Task::where('id', $chatId)->where('agent_id', $agentId)->first();

        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_NOT_FOUND);
        }

        return $task;
    }

    /**
     * Whether the chat simply is not this agent's — as opposed to existing
     * but being in a state that forbids the call. The two answer 404 and 409
     * respectively, and a caller must not be able to tell "no such chat" from
     * "chat exists, wrong agent" by probing ids.
     */
    public function chatMissing(int $chatId, int $agentId): bool
    {
        return !Task::where('id', $chatId)->where('agent_id', $agentId)->exists();
    }

    /**
     * Always paginated, defaulting to a page rather than to everything.
     *
     * Each row goes through `AgentApiChatProjection::status()`, which adds
     * up to two more queries for a chat with no `final_response` or one
     * parked on an approval. An agent with a long backlog would turn one
     * uncapped request into an unbounded body and O(n) queries — and the
     * per-key rate limit bounds request count, not response size.
     *
     * @return list<Task>
     */
    public function listChats(int $agentId, ?string $since, ?int $page, ?int $perPage): array
    {
        $query = Task::where('agent_id', $agentId)
            ->orderByDesc('id');

        if ($since !== null && $since !== '') {
            $query->where('updated_at', '>', $since);
        }

        $query->forPage(
            max(1, $page ?? 1),
            $perPage !== null && $perPage > 0 ? min($perPage, self::MAX_PER_PAGE) : self::DEFAULT_PER_PAGE,
        );

        return $query->get()->all();
    }

    /**
     * @param list<string> $mediaIds ids already checked by
     *   {@see \Spora\Http\AgentApi\AgentApiChatController::readableMediaIds()}
     */
    public function startChat(
        int $ownerUserId,
        int $agentId,
        string $prompt,
        ?int $maxSteps,
        array $mediaIds,
    ): Task {
        $resource = $this->taskService->startTask(
            $ownerUserId,
            $agentId,
            $prompt,
            $maxSteps,
            null,
            $mediaIds,
        );

        return Task::find((int) $resource['id'])
            ?? throw new InvalidArgumentException(self::ERR_NOT_FOUND);
    }

    /**
     * @param list<string> $mediaIds ids already checked by
     *   {@see \Spora\Http\AgentApi\AgentApiChatController::readableMediaIds()}
     */
    public function continueChat(
        int $chatId,
        int $agentId,
        int $ownerUserId,
        string $text,
        ?int $additionalSteps,
        array $mediaIds,
    ): Task {
        $this->requireTask($chatId, $agentId);

        // The user-scoped continue is the only implementation of the
        // continue state machine; scoping happens here, one line above.
        $this->taskService->continueTask($chatId, $ownerUserId, $text, $additionalSteps, $mediaIds);

        return $this->requireTask($chatId, $agentId);
    }

    /**
     * Record the operator's approval and queue the chat again.
     *
     * @param list<array{provider_call_id: string, decision: 'approve'|'reject', arguments?: array<string, mixed>, reason?: string}> $decisions
     *
     * @throws InvalidArgumentException when the chat is not this agent's, or is
     *     not pending approval — {@see self::chatMissing()} tells those apart.
     */
    public function approve(int $chatId, int $agentId, int $ownerUserId, array $decisions): Task
    {
        $this->requireTask($chatId, $agentId);
        $this->taskService->approveTask($chatId, $ownerUserId, $decisions);

        return $this->requireTask($chatId, $agentId);
    }

    /**
     * Record the operator's rejection and close the chat.
     *
     * @param string $reason Surfaced to the LLM so it can choose an alternative.
     *
     * @throws InvalidArgumentException when the chat is not this agent's, or is
     *     not pending approval — {@see self::chatMissing()} tells those apart.
     */
    public function reject(int $chatId, int $agentId, int $ownerUserId, string $reason): Task
    {
        $this->requireTask($chatId, $agentId);
        $this->taskService->rejectTask($chatId, $ownerUserId, $reason);

        return $this->requireTask($chatId, $agentId);
    }

    /**
     * Record the operator's answer and queue the chat again.
     *
     * @param string $toolCallId The pending tool call the answer belongs to.
     * @param string $content    The answer as the operator wrote it.
     *
     * @throws InvalidArgumentException when the chat is not this agent's, or is
     *     not awaiting an answer — {@see self::chatMissing()} tells those apart.
     */
    public function answer(int $chatId, int $agentId, int $ownerUserId, string $toolCallId, string $content): Task
    {
        $this->requireTask($chatId, $agentId);
        $this->taskService->answerTask($chatId, $ownerUserId, $toolCallId, $content);

        return $this->requireTask($chatId, $agentId);
    }

    /**
     * A media asset is readable when the agent produced it, when it belongs
     * to a chat in the agent's own tree (which is how a sub-agent's output
     * becomes reachable through its parent), or when a chat of this agent
     * attached it.
     */
    public function isMediaReadable(string $mediaId, int $agentId): bool
    {
        $asset = $this->mediaArchive->find($mediaId);

        if ($asset === null) {
            return false;
        }

        return $this->isAssetReadable($asset, $agentId);
    }

    public function findMedia(string $mediaId, int $agentId): ?MediaAsset
    {
        $asset = $this->mediaArchive->find($mediaId);

        if ($asset === null || !$this->isAssetReadable($asset, $agentId)) {
            return null;
        }

        return $asset;
    }

    private function isAssetReadable(MediaAsset $asset, int $agentId): bool
    {
        if ($asset->agent_id !== null && (int) $asset->agent_id === $agentId) {
            return true;
        }

        if ($asset->task_id !== null && $this->isTaskInScope((int) $asset->task_id, $agentId)) {
            return true;
        }

        return $this->isAttachedToScopedChat((string) $asset->id, $agentId);
    }

    /**
     * Whether a task belongs to this agent, or descends from one of its tasks.
     *
     * Walks *up* the `parent_task_id` chain rather than down: the asset on a
     * sub-agent's chat carries the sub-task's id, and it is the parent's chat
     * that a caller of the parent can see. A depth cap stops a cycle in the
     * chain from hanging the request.
     */
    private function isTaskInScope(int $taskId, int $agentId): bool
    {
        $seen = [];
        $current = $taskId;

        for ($depth = 0; $depth < self::MAX_ANCESTOR_WALK; $depth++) {
            $task = Task::find($current);

            if ($task === null || isset($seen[$current])) {
                return false;
            }

            $seen[$current] = true;

            if ((int) $task->agent_id === $agentId) {
                return true;
            }

            $seen[$current] = true;

            // 0 is the end-of-chain sentinel: ids are auto-increment from 1,
            // so the next `find()` misses and the climb stops.
            $current = $task->parent_task_id === null ? 0 : (int) $task->parent_task_id;
        }

        return false;
    }
    /**
     * An upload ingested for a chat carries no `agent_id` and no `task_id`
     * on the media row — the linkage is the `task_history` attachment row —
     * so that is the only place an inbound attachment can be traced back.
     */
    private function isAttachedToScopedChat(string $mediaId, int $agentId): bool
    {
        return DB::table('task_history')
            ->join('tasks', 'tasks.id', '=', 'task_history.task_id')
            ->where('tasks.agent_id', $agentId)
            ->where('task_history.attachments', 'like', '%"' . $mediaId . '"%')
            ->exists();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolCalls(int $chatId): array
    {
        return ToolCall::where('task_id', $chatId)
            ->orderBy('id')
            ->get()
            ->map(fn(ToolCall $call): array => $this->toolCallSerializer->toArray($call))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(int $chatId, ?int $sinceSequence): array
    {
        $query = TaskHistory::where('task_id', $chatId)->orderBy('sequence');

        if ($sinceSequence !== null) {
            $query->where('sequence', '>', $sinceSequence);
        }

        return TaskHistorySerializer::buildHistoryPayload($query->get())['history'];
    }

    /**
     * @return array<string, int>
     */
    private function totals(int $chatId): array
    {
        $rows = TaskHistory::where('task_id', $chatId)->orderBy('sequence')->get();

        // `buildHistoryPayload` returns the usage rows already flattened to
        // arrays, which is what `aggregateUsage()` sums over — passing the
        // `Usage` objects themselves would not typecheck against its contract.
        return TaskHistorySerializer::aggregateUsage(
            TaskHistorySerializer::buildHistoryPayload($rows)['usages'],
        );
    }
}

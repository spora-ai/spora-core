<?php

declare(strict_types=1);

namespace Spora\Services\ApiKeys;

use InvalidArgumentException;
use Spora\Auth\ApiKeyScope;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall;
use Spora\Services\TaskHistorySerializer;
use Spora\Services\TaskServiceInterface;
use Spora\Services\ToolCallSerializer;

/**
 * The chat and media surface behind an API key, limited to what the key's
 * reach allows.
 *
 * The *scope* is decided here rather than by the user-scoped
 * `TaskServiceInterface` methods, whose visibility comes from
 * `visiblePrincipalIds()`: a principal is shared by every group member,
 * which is the right rule for the admin UI and far too wide for a
 * credential. A key may only ever reach the agents its own scope names.
 *
 * The agent set is a query, not a list: the global scope would otherwise
 * have to read every group-owned agent on the install before it could
 * answer the first question.
 */
final class ApiKeyChatAccess
{
    /**
     * The error vocabulary, public because the controllers answer with these
     * strings and the OpenAPI annotations describe them — three places that
     * have to keep agreeing, and cannot when each holds its own copy.
     */
    public const ERR_NOT_FOUND = 'Chat not found.';

    public const ERR_NO_SUCH_CHAT = 'No such chat on this agent.';

    private const DEFAULT_PER_PAGE = 25;

    /** Above this the per-row projection cost makes a page expensive. */
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly TaskServiceInterface $taskService,
        private readonly ToolCallSerializer $toolCallSerializer,
        private readonly ApiKeyReach $reach,
    ) {}

    /**
     * The transcript payload: the `taskDetailResource` shape minus the retry
     * and abort bookkeeping, with nulls where that resource omits a key.
     * Kept separate rather than delegating, because this surface has no retry
     * policy to report — and because it carries `pending_questions`, which is
     * what a parked chat's questions arrive on.
     *
     * Throws rather than returning null when the chat is outside the key's
     * reach — every caller needs a 404 for that and only for that, and a
     * nullable return would make "missing chat" indistinguishable from "no
     * history".
     *
     * @return array<string, mixed>
     */
    public function transcript(ApiKeyContext $ctx, int $chatId, ?int $sinceSequence): array
    {
        $task = $this->requireTask($ctx, $chatId);

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
     * The chat, when it is inside the key's reach.
     *
     * @throws InvalidArgumentException when it is not — callers tell that
     *     apart from a wrong-state 409 with {@see self::chatMissing()}.
     */
    public function requireTask(ApiKeyContext $ctx, int $chatId): Task
    {
        $task = Task::where('id', $chatId)
            ->whereIn('agent_id', $this->reach->agentIds($ctx))
            ->first();

        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_NOT_FOUND);
        }

        return $task;
    }

    /**
     * Whether the chat simply is not reachable — as opposed to existing but
     * being in a state that forbids the call. The two answer 404 and 409
     * respectively, and a caller must not be able to tell "no such chat" from
     * "chat exists, wrong agent" by probing ids.
     */
    public function chatMissing(ApiKeyContext $ctx, int $chatId): bool
    {
        return !Task::where('id', $chatId)
            ->whereIn('agent_id', $this->reach->agentIds($ctx))
            ->exists();
    }

    /**
     * Always paginated, defaulting to a page rather than to everything.
     *
     * Each row goes through `ApiKeyChatProjection::status()`, which adds
     * up to two more queries for a chat with no `final_response` or one
     * parked on an approval. An agent with a long backlog would turn one
     * uncapped request into an unbounded body and O(n) queries — and the
     * per-key rate limit bounds request count, not response size.
     *
     * @return list<Task>
     */
    public function listChats(ApiKeyContext $ctx, ?string $since, ?int $page, ?int $perPage): array
    {
        $query = Task::whereIn('agent_id', $this->reach->agentIds($ctx))
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
     *   {@see \Spora\Http\ScopedApi\ScopedApiChatController::readableMediaIds()}
     */
    public function startChat(
        ApiKeyContext $ctx,
        int $agentId,
        string $prompt,
        ?int $maxSteps,
        array $mediaIds,
    ): Task {
        $agent = $this->requireAgent($ctx, $agentId);

        $resource = $this->taskService->startTask(
            $this->ownerFor($ctx, $agent),
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
     *   {@see \Spora\Http\ScopedApi\ScopedApiChatController::readableMediaIds()}
     */
    public function continueChat(
        ApiKeyContext $ctx,
        int $chatId,
        string $text,
        ?int $additionalSteps,
        array $mediaIds,
    ): Task {
        $task = $this->requireTask($ctx, $chatId);

        // The user-scoped continue is the only implementation of the
        // continue state machine; scoping happens here, one line above.
        $this->taskService->continueTask(
            $chatId,
            $this->ownerFor($ctx, $this->agentOf($task)),
            $text,
            $additionalSteps,
            $mediaIds,
        );

        return $this->requireTask($ctx, $chatId);
    }

    /**
     * Record the operator's approval and queue the chat again.
     *
     * @param list<array{provider_call_id: string, decision: 'approve'|'reject', arguments?: array<string, mixed>, reason?: string}> $decisions
     *
     * @throws InvalidArgumentException when the chat is not reachable, or is
     *     not pending approval — {@see self::chatMissing()} tells those apart.
     */
    public function approve(ApiKeyContext $ctx, int $chatId, array $decisions): Task
    {
        $task = $this->requireTask($ctx, $chatId);
        $this->taskService->approveTask($chatId, $this->ownerFor($ctx, $this->agentOf($task)), $decisions);

        return $this->requireTask($ctx, $chatId);
    }

    /**
     * Record the operator's rejection and close the chat.
     *
     * @param string $reason Surfaced to the LLM so it can choose an alternative.
     *
     * @throws InvalidArgumentException when the chat is not reachable, or is
     *     not pending approval — {@see self::chatMissing()} tells those apart.
     */
    public function reject(ApiKeyContext $ctx, int $chatId, string $reason): Task
    {
        $task = $this->requireTask($ctx, $chatId);
        $this->taskService->rejectTask($chatId, $this->ownerFor($ctx, $this->agentOf($task)), $reason);

        return $this->requireTask($ctx, $chatId);
    }

    /**
     * Record the operator's answer and queue the chat again.
     *
     * @param string $toolCallId The pending tool call the answer belongs to.
     * @param string $content    The answer as the operator wrote it.
     *
     * @throws InvalidArgumentException when the chat is not reachable, or is
     *     not awaiting an answer — {@see self::chatMissing()} tells those apart.
     */
    public function answer(ApiKeyContext $ctx, int $chatId, string $toolCallId, string $content): Task
    {
        $task = $this->requireTask($ctx, $chatId);
        $this->taskService->answerTask(
            $chatId,
            $this->ownerFor($ctx, $this->agentOf($task)),
            $toolCallId,
            $content,
        );

        return $this->requireTask($ctx, $chatId);
    }

    /**
     * The agent, when it is inside the key's reach.
     *
     * @throws InvalidArgumentException when it is not — a caller must not be
     *     able to probe which agent ids exist.
     */
    private function requireAgent(ApiKeyContext $ctx, int $agentId): Agent
    {
        return $this->reach->targetAgent($ctx, $agentId)
            ?? throw new InvalidArgumentException(self::ERR_NOT_FOUND);
    }

    /**
     * The user a chat with this agent is attributed to.
     *
     * The agent scope knows its own owner already — the middleware resolved
     * it — while the group and global scopes resolve the owner of the
     * *target* agent, because the target changes per request.
     */
    private function ownerFor(ApiKeyContext $ctx, Agent $agent): int
    {
        if ($ctx->scope === ApiKeyScope::Agent) {
            return $ctx->ownerUserId ?? throw new InvalidArgumentException(self::ERR_NOT_FOUND);
        }

        return $this->reach->ownerUserId($agent)
            ?? throw new InvalidArgumentException(self::ERR_NOT_FOUND);
    }

    private function agentOf(Task $task): Agent
    {
        $agent = Agent::find((int) $task->agent_id);

        if ($agent === null) {
            throw new InvalidArgumentException(self::ERR_NOT_FOUND);
        }

        return $agent;
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

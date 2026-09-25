<?php

declare(strict_types=1);

namespace Spora\Agents;

use Psr\Log\LoggerInterface;
use Spora\Agents\Exceptions\ToolNotEnabledException;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\ScrubDataUrls;
use Spora\Services\SubAgentServiceInterface;
use Spora\Services\TaskHistorySerializer;
use Spora\Services\Text\Utf8Sanitizer;
use Spora\Services\ToolCallSerializer;
use Spora\Tools\PendingQuestionBatch;
use Throwable;

/**
 * Post-tool-batch disposition for {@see TickPhaseRunner}.
 *
 * Pulled out so the tick runner stays under the per-class method-count
 * limit while the dispatch + park + complete paths each decompose far
 * enough for every helper to satisfy the per-method return-count rule.
 *
 * Reads {@see $singleStep} off the public field (set per outer tick by
 * {@see Orchestrator::tick()} via {@see TickPhaseRunner::setSingleStep()})
 * so the SPA's client-worker mode keeps stopping after one LLM turn.
 */
final class ToolCallBatchHandler
{
    /**
     * @param list<object> $toolInstances
     */
    public function __construct(
        private readonly Orchestrator $orchestrator,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?NotificationService $notificationService = null,
        private readonly ?MercurePublisherInterface $mercure = null,
        private readonly ?ToolCallSerializer $toolCallSerializer = null,
        private readonly array $toolInstances = [],
        private readonly LeaseGuard $leaseGuard = new LeaseGuard(),
        private readonly ?SubAgentServiceInterface $subAgent = null,
    ) {}

    public bool $singleStep = false;

    /**
     * @param list<DriverToolCall> $toolCalls
     * @return array{0: list<DriverToolCall>, 1: list<array{toolCall: DriverToolCall, batch: PendingQuestionBatch}>}
     */
    public function dispatchToolCalls(Task $task, Agent $agent, array $toolCalls): array
    {
        $pendingApproval = [];
        $pendingInput = [];
        foreach ($toolCalls as $toolCall) {
            $this->dispatchOneToolCall($toolCall, $task, $agent, $pendingApproval, $pendingInput);
        }
        return [$pendingApproval, $pendingInput];
    }

    /**
     * Abort-bail: a user abort could have landed between this tick's
     * claim and the completion of the tool batch. We accept the user's
     * request up to this tool boundary — once the latest tool returned,
     * we re-read the status before either kicking the next tick or
     * handing the loop off to the parent-resume hook. If the row is
     * `ABORTED`, no further LLM traffic happens this tick.
     *
     * Publish the just-completed tool output BEFORE the bail: the chat
     * relies on Mercure for live tool output. If we published only on
     * the next tick (which never arrives for an aborted task), the user
     * would have to reload the page to see the tool result that landed
     * the same instant they clicked Abort. Re-read the row before
     * publishing so the payload reflects the current DB state.
     */
    public function completeTickAfterTools(Task $task): void
    {
        if ($this->maybeBailForAbort($task)) {
            return;
        }
        $this->maybeResumeParentFromBatchBoundary($task->id);
        if ($this->isTaskAborted($task->id)) {
            return;
        }
        $this->continueOrHandOff($task);
    }

    /**
     * @param list<DriverToolCall> $pendingApproval
     * @param list<array{toolCall: DriverToolCall, batch: PendingQuestionBatch}> $pendingInput
     */
    public function parkTaskForPending(
        Task $task,
        Agent $agent,
        array $pendingApproval,
        array $pendingInput,
    ): void {
        // Input takes precedence over approval when both queues are
        // non-empty in the same tick — operators answer the question
        // batch first; the queued approvals stay in pending_state and
        // re-present after the answer transition resumes the loop.
        $isAwaitingInput = $pendingInput !== [];

        $state = new AgentState(
            taskId: $task->id,
            agentId: $agent->id,
            pendingToolCalls: $pendingApproval,
            messageSnapshot: $this->orchestrator->buildMessages($task->id),
            stepCount: $task->step_count,
            maxSteps: $task->max_steps,
            pausedAt: date('Y-m-d\TH:i:s\Z'),
            pendingQuestions: array_map(
                static fn(array $entry): PendingQuestionBatch => $entry['batch'],
                $pendingInput,
            ),
        );

        $task->status        = $isAwaitingInput ? 'AWAITING_INPUT' : 'PENDING_APPROVAL';
        $task->pending_state = $state->toJson();
        $task->save();

        $this->logger?->info(
            $isAwaitingInput ? 'Task paused — user input needed' : 'Task paused — approval needed',
            [
                'task_id'    => $task->id,
                'tool_count' => $isAwaitingInput ? count($pendingInput) : count($pendingApproval),
                'tools'      => $this->pausedToolNames($pendingApproval, $pendingInput, $isAwaitingInput),
            ],
        );

        if ($isAwaitingInput) {
            $this->notificationService?->notifyAwaitingInput($task);
        } else {
            $this->notificationService?->notifyPendingApproval($task);
        }

        $this->publishIntermediateState($task);
    }

    /**
     * Pull the most-recently-appended PendingQuestionBatch out of the
     * task's pending_state column. Used to fold a just-executed
     * ask_user_question call into the AgentState snapshot before
     * writing it.
     *
     * Returns null if pending_state is missing, malformed, or contains
     * zero batches (caller treats null as "nothing to fold in").
     */
    public function extractLastPendingBatch(Task $task): ?PendingQuestionBatch
    {
        $state = $this->decodePendingState($task);
        if ($state === null || $state->pendingQuestions === []) {
            return null;
        }
        return $state->pendingQuestions[array_key_last($state->pendingQuestions)];
    }

    /**
     * @param list<DriverToolCall> $pendingApproval
     * @param list<array{toolCall: DriverToolCall, batch: PendingQuestionBatch}> $pendingInput
     */
    private function dispatchOneToolCall(
        DriverToolCall $toolCall,
        Task $task,
        Agent $agent,
        array &$pendingApproval,
        array &$pendingInput,
    ): void {
        try {
            $disposition = $this->orchestrator->toolCallExecutor->executeOrQueue($toolCall, $agent, $task);
            if ($disposition === ToolCallDisposition::AwaitingApproval) {
                $pendingApproval[] = $toolCall;
                return;
            }
            if ($disposition === ToolCallDisposition::AwaitingInput) {
                $batch = $this->extractLastPendingBatch($task);
                if ($batch !== null) {
                    $pendingInput[] = ['toolCall' => $toolCall, 'batch' => $batch];
                }
            }
        } catch (ToolNotEnabledException $e) {
            $this->orchestrator->appendHistory(
                taskId: $task->id,
                role: 'tool',
                content: ScrubDataUrls::scrub(Utf8Sanitizer::scrubString(
                    "Tool '{$toolCall->toolName}' is not enabled for this agent. The tool may have been revoked; do not propose it again.",
                )),
                context: new HistoryMessageContext(
                    toolCallId: $toolCall->providerCallId,
                    toolName: $toolCall->toolName,
                ),
            );
        } catch (Throwable $e) {
            $this->orchestrator->appendHistory(
                taskId: $task->id,
                role: 'tool',
                content: 'System Error: ' . $e->getMessage(),
                context: new HistoryMessageContext(
                    toolCallId: $toolCall->providerCallId,
                    toolName: $toolCall->toolName,
                ),
            );
        }
    }

    private function maybeBailForAbort(Task $task): bool
    {
        $latestStatus = Task::where('id', $task->id)->value('status');
        $this->publishIntermediateState(Task::find($task->id) ?? $task);
        if ($latestStatus !== 'ABORTED') {
            return false;
        }
        $this->logger?->info('Tick bailed — task was aborted after tool batch', [
            'task_id' => $task->id,
        ]);
        return true;
    }

    /**
     * Sync-mode auto-approve batch boundary: every tool in this turn
     * ran inline (no ApprovedBatchExecutor involved), so the resume hook
     * in ApprovedBatchExecutor::triggerBatchBoundaryResume never fires
     * for this path. Mirror it here so any spawned sub_agents get a
     * chance to wake their parent up at the end of the turn. The
     * worker-mode equivalent lives in `executeApprovedPendingToolsForTask`.
     */
    private function maybeResumeParentFromBatchBoundary(int $parentTaskId): void
    {
        if ($this->subAgent === null) {
            return;
        }
        try {
            $this->subAgent->maybeResumeParentForParent($parentTaskId);
        } catch (Throwable $e) {
            $this->logger?->warning('SubAgentService::maybeResumeParentForParent failed at worker batch boundary', [
                'task_id'   => $parentTaskId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function isTaskAborted(int $taskId): bool
    {
        return Task::where('id', $taskId)->value('status') === 'ABORTED';
    }

    private function continueOrHandOff(Task $task): void
    {
        // Before recursive tick — keep the lease alive across the next
        // tool batch + LLM round-trip so the reaper does not flip the
        // row mid-batch.
        $this->leaseGuard->extend($task->id);
        if ($this->singleStep) {
            $this->resetForSingleStepBrowser($task);
            return;
        }
        $this->orchestrator->tick($task->id);
    }

    /**
     * Client-worker mode: stop after one LLM turn so the SPA sees this
     * batch of tool calls. Flip status back to QUEUED so the browser's
     * next /tick can CAS-claim the row. Clear the lease so the reaper
     * doesn't pick up the row while the browser is preparing the next
     * tick — the browser re-claims it with its own lease_owner.
     */
    private function resetForSingleStepBrowser(Task $task): void
    {
        Task::where('id', $task->id)
            ->where('status', 'RUNNING')
            ->update([
                'status'           => 'QUEUED',
                'lease_owner'      => null,
                'lease_expires_at' => null,
            ]);
        $this->publishIntermediateState(Task::find($task->id) ?? $task);
    }

    /**
     * @param list<DriverToolCall> $pendingApproval
     * @param list<array{toolCall: DriverToolCall, batch: PendingQuestionBatch}> $pendingInput
     */
    private function pausedToolNames(array $pendingApproval, array $pendingInput, bool $isAwaitingInput): string
    {
        $source = $isAwaitingInput
            ? array_map(static fn(array $entry): DriverToolCall => $entry['toolCall'], $pendingInput)
            : $pendingApproval;
        return implode(', ', array_unique(array_map(
            static fn(DriverToolCall $tc) => $tc->toolName,
            $source,
        )));
    }

    private function decodePendingState(Task $task): ?AgentState
    {
        if (!is_string($task->pending_state) || $task->pending_state === '') {
            return null;
        }
        try {
            return AgentState::fromJson($task->pending_state);
        } catch (Throwable) {
            return null;
        }
    }

    public function publishIntermediateState(Task $task): void
    {
        if ($this->mercure === null) {
            return;
        }

        // Injected serializer is already wired with the icon resolver (see
        // ContainerDefinitions) — the fallback below therefore runs without one.
        $serializer = $this->toolCallSerializer ?? new ToolCallSerializer($this->toolInstances);

        $historyRows = $task->taskHistory()->orderBy('sequence')->get();
        $historyPayload = TaskHistorySerializer::buildHistoryPayload($historyRows);
        $totals = TaskHistorySerializer::aggregateUsage($historyPayload['usages']);

        $taskData = [
            'id' => $task->id,
            'status' => $task->status,
            'step_count' => $task->step_count,
            'tool_calls' => $task->toolCalls->map(fn(\Spora\Models\ToolCall $tc) => $serializer->toArray($tc))->all(),
            'history' => $historyPayload['history'],
            'totals' => $totals,
            // Surface the full `data` snapshot so the chat UI gets live
            // tool-managed state (TodoTool writes `data.todos`,
            // SubAgentTool writes `data.spawned_sub_task_ids`, etc.)
            // without waiting for the slow detail poll.
            'data' => $task->data,
        ];

        // Surface pending question batches on the Mercure event so the
        // chat UI can render the picker without an extra `/show` fetch.
        // We re-read the column — the in-memory $task was loaded before
        // the executor wrote the new batch, and the runner's own write
        // happened just before this method runs.
        $fresh = Task::where('id', $task->id)->first();
        if ($fresh !== null
            && $fresh->status === 'AWAITING_INPUT'
            && is_string($fresh->pending_state)
            && $fresh->pending_state !== ''
        ) {
            try {
                $state = AgentState::fromJson($fresh->pending_state);
                if ($state->pendingQuestions !== []) {
                    $taskData['pending_questions'] = array_map(
                        static fn(PendingQuestionBatch $b): array => $b->toArray(),
                        $state->pendingQuestions,
                    );
                }
            } catch (Throwable) {
                // Bad JSON or shape drift — fall through without pending_questions.
            }
        }

        $this->mercure->publishForPrincipal($task->id, $task->principalOwnerId(), $taskData);
    }
}

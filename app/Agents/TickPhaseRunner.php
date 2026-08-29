<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Spora\Agents\Exceptions\ToolNotEnabledException;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\PrincipalResolver;
use Spora\Services\ScrubDataUrls;
use Spora\Services\SubAgentServiceInterface;
use Spora\Services\Text\Utf8Sanitizer;
use Spora\Services\ToolCallSerializer;
use Spora\Tools\Traits\HasOperations;
use Throwable;

/**
 * Runs the three tick phases (claim → LLM call → write results) for the
 * orchestrator. Holds the orchestrator by reference to call back into
 * `appendHistory`, `tick`, `buildMessages`, `errorClassifier`,
 * `contextWindowRecovery`, and `retryScheduler` (mirrors
 * {@see ToolCallExecutor}).
 */
final class TickPhaseRunner
{
    /**
     * @param list<object> $toolInstances
     */
    public function __construct(
        private readonly Orchestrator $orchestrator,
        private readonly DriverFactory $driverFactory,
        private readonly array $toolInstances,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?NotificationService $notificationService = null,
        private readonly ?MercurePublisherInterface $mercure = null,
        private readonly ?ToolCallSerializer $toolCallSerializer = null,
        private readonly ?SubAgentServiceInterface $subAgent = null,
        private readonly ?PrincipalResolver $principalResolver = null,
        public readonly LeaseGuard $leaseGuard = new LeaseGuard(),
    ) {}

    /**
     * Set by Orchestrator::tick from the per-call OrchestratorConfig.
     * When `true`, the post-tool-batch recursive tick in
     * {@see handleToolCalls()} is suppressed — the orchestrator stops
     * after one LLM turn so the SPA sees the intermediate tool-call
     * batch. The browser's tick loop fires the next `/tick` when the
     * row is still QUEUED, picking up from where this tick stopped.
     *
     * Field (not method) so the orchestrator can flip it cheaply on
     * every outer tick call without a setter, and so the recursive
     * `Orchestrator::tick()` inherits it from the outermost call
     * automatically.
     */
    public bool $singleStep = false;

    public function runTick(int $taskId): void
    {
        $task = $this->lockRunningTaskForTick($taskId);
        if ($task === null) {
            return;
        }

        // Worker-mode pickup: run approved tools persisted with `executed_at IS NULL`
        // before the LLM round-trip so the next assistant message sees the results.
        $this->executeApprovedPendingToolsForTask($task);

        // After tool batch write — a slow tool batch must not let the lease
        // expire before the LLM turn starts.
        $this->leaseGuard->extend($taskId);

        // The tool above may have flipped the task out of RUNNING — the
        // `sub_agent` op suspends the parent (`status = AWAITING_SUB_AGENTS`)
        // before the child tick returns, and other ops can mark the task
        // COMPLETED or FAILED inline. The next batch-boundary hook (sync:
        // ApprovedBatchExecutor, worker: the same code path here) or the
        // per-child completion hook will resume the parent on a later tick
        // — this turn must NOT call the LLM, or the parent would advance
        // with a stale context and could complete without the child's
        // result ever landing in history.
        $currentStatus = Task::where('id', $taskId)->value('status');
        if ($currentStatus !== 'RUNNING') {
            return;
        }

        $this->dispatchLlmTurn($taskId, $task);

        // After LLM response — extend so the next recursive tick (or the
        // post-approval pickup on the next daemon tick) does not race the
        // reaper.
        $this->leaseGuard->extend($taskId);
    }

    /**
     * Prepare the LLM request, dispatch it, and apply the response. Owns
     * the post-LLM abort re-check (a user abort that landed during the
     * provider round-trip) and the surrounding `increment/step_count`
     * bookkeeping — extracted from {@see runTick()} to keep that method
     * at ≤3 `return` statements.
     */
    private function dispatchLlmTurn(int $taskId, Task $task): void
    {
        try {
            $context = $this->prepareTickContext($task);
        } catch (RuntimeException $e) {
            $this->orchestrator->errorClassifier->markTaskNoLlmConfiguration($taskId, $e);
            throw $e;
        }

        Task::where('id', $taskId)->increment('step_count');

        try {
            $response = $this->dispatchLlmRequest($context);
        } catch (Throwable $e) {
            $this->handleTickFailure($taskId, $context, $e);
            return;
        }

        // Abort-bail after the LLM round-trip: while we were waiting
        // on the provider to respond, the user may have hit the Abort
        // button. The status was RUNNING when we *started* the call,
        // but it is ABORTED now — without this re-check the loop would
        // process the LLM response as if the task were still running
        // and call completeTaskWithResponse, flipping status back to
        // COMPLETED and discarding the abort.
        $currentStatus = Task::where('id', $taskId)->value('status');
        if ($currentStatus !== 'RUNNING') {
            $this->logger?->info('Tick bailed — task was aborted while waiting on LLM', [
                'task_id' => $taskId,
                'status'  => $currentStatus,
            ]);
            return;
        }

        $this->handleTickLlmResponse($context, $response);
    }

    /**
     * Worker-mode pickup: runs rows in the post-approval / pre-execution
     * sentinel state (`status='APPROVED' AND executed_at IS NULL`) so the
     * daemon picks up the work that {@see ApprovedBatchExecutor::execute()}
     * deferred from the HTTP path.
     */
    private function executeApprovedPendingToolsForTask(Task $task): void
    {
        $pendingRows = ToolCallModel::where('task_id', $task->id)
            ->where('status', 'APPROVED')
            ->whereNull('executed_at')
            ->orderBy('id')
            ->get();

        if ($pendingRows->isEmpty()) {
            return;
        }

        $operationMap = ToolCallModel::where('task_id', $task->id)
            ->whereIn('status', ['PENDING_APPROVAL', 'AWAITING_FINAL_APPROVAL', 'APPROVED'])
            ->get(['provider_call_id', 'operation'])
            ->mapWithKeys(static function (ToolCallModel $row): array {
                $op = $row->getAttribute('operation');
                return is_string($op) && $op !== ''
                    ? [(string) $row->getAttribute('provider_call_id') => $op]
                    : [];
            })
            ->all();

        foreach ($pendingRows as $row) {
            $providerCallId = (string) $row->getAttribute('provider_call_id');
            $approvedArgs  = is_string($row->getAttribute('approved_arguments'))
                ? json_decode((string) $row->getAttribute('approved_arguments'), true, 512, JSON_THROW_ON_ERROR)
                : (array) $row->getAttribute('approved_arguments');
            $toolName      = (string) $row->getAttribute('tool_name');
            $operationName = $operationMap[$providerCallId] ?? null;

            $this->executeOneApprovedPendingTool($task, $providerCallId, $toolName, $approvedArgs, $operationName);
        }

        // Worker-mode batch boundary — mirror the sync-mode boundary in
        // ApprovedBatchExecutor so the resume fires once per batch, never per-child.
        $this->maybeResumeParentFromBatchBoundary($task->id);
    }

    /**
     * Worker-mode batch boundary hook. Tolerates missing DI (no SubAgentService
     * wired) by no-op'ing, matching {@see maybeResumeParentForChild()}'s pattern.
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

    /**
     * @param array<string, mixed> $approvedArgs
     */
    private function executeOneApprovedPendingTool(
        Task $task,
        string $providerCallId,
        string $toolName,
        array $approvedArgs,
        ?string $operationName,
    ): void {
        $toolInstance = $this->orchestrator->resolveToolByName($toolName);

        // Re-check authorization at execution time: between the user approving
        // the batch and the daemon picking it up here, an admin may have revoked
        // this tool from the agent or disabled the specific op. The
        // proposal-time gate in {@see ToolCallExecutor::executeOrQueue()} only
        // runs at proposal time and cannot defend against drift.
        $toolClass = get_class($toolInstance);
        $enabledClasses = AgentTool::where('agent_id', $task->agent_id)
            ->pluck('tool_class')->all();

        if (!in_array($toolClass, $enabledClasses, true)) {
            $this->orchestrator->recordRevokedToolCall(
                task: $task,
                providerCallId: $providerCallId,
                toolName: $toolName,
                rejectReason: "tool '{$toolName}' was revoked from this agent before approval was processed",
            );
            return;
        }

        if ($operationName !== null
            && in_array(HasOperations::class, class_uses_recursive($toolClass), true)
            && !$this->orchestrator->isOperationEnabled($toolInstance, $operationName, $task->agent_id)
        ) {
            $this->orchestrator->recordRevokedToolCall(
                task: $task,
                providerCallId: $providerCallId,
                toolName: $toolName,
                rejectReason: "operation '{$operationName}' of tool '{$toolName}' was disabled before approval was processed",
            );
            return;
        }

        try {
            SchemaValidator::validate($approvedArgs, $toolInstance->getParametersSchema(), $operationName);
        } catch (Throwable $e) {
            $this->recordApprovedFailure($task, $providerCallId, $toolName, 'Validation Error: ' . $e->getMessage());
            return;
        }

        // safeExecute() catches every Throwable internally — no outer try/catch needed.
        $result = $this->orchestrator->safeExecute($toolInstance, $approvedArgs, $task->agent_id, $task->id);

        // Query-builder update() bypasses Eloquent's `array` cast on `approved_arguments`,
        // which would double-encode the JSON string. Same anti-pattern PR #150 fixed.
        ToolCallModel::where('task_id', $task->id)
            ->where('provider_call_id', $providerCallId)
            ->update([
                'result_content' => ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content)),
                'result_data'    => $result->data ? json_encode($result->data, JSON_THROW_ON_ERROR) : null,
                'executed_at'    => date(Orchestrator::DB_TIMESTAMP_FORMAT),
            ]);

        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content)),
            context: new HistoryMessageContext(
                toolCallId: $providerCallId,
                toolName: $toolName,
            ),
        );
    }

    private function recordApprovedFailure(
        Task $task,
        string $providerCallId,
        string $toolName,
        string $message,
    ): void {
        $scrubbed = ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($message));

        ToolCallModel::where('task_id', $task->id)
            ->where('provider_call_id', $providerCallId)
            ->update([
                'result_content' => $scrubbed,
                'executed_at'    => date(Orchestrator::DB_TIMESTAMP_FORMAT),
            ]);

        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: $scrubbed,
            context: new HistoryMessageContext(
                toolCallId: $providerCallId,
                toolName: $toolName,
            ),
        );
    }

    private function lockRunningTaskForTick(int $taskId): ?Task
    {
        $taskRef = null;

        Capsule::connection()->transaction(function () use ($taskId, &$taskRef): void {
            $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();

            if ($task->status !== 'RUNNING') {
                return;
            }

            if ($task->step_count >= $task->max_steps) {
                $task->status         = 'FAILED';
                $task->failure_reason = Utf8Sanitizer::scrubString('Max steps reached.');
                $task->save();
                return;
            }

            $taskRef = $task;
        });

        return $taskRef;
    }

    /**
     * @return array{
     *   task: Task,
     *   agent: Agent,
     *   enabledClasses: list<string>,
     *   contextWindow: int,
     *   maxTokensOutput: int,
     *   request: LLMRequest
     * }
     */
    private function prepareTickContext(Task $task): array
    {
        $agent = Agent::findOrFail($task->agent_id);
        $enabledClasses = AgentTool::where('agent_id', $agent->id)->pluck('tool_class')->toArray();

        $llmConfig = $this->orchestrator->llmConfigResolver->resolveLlmConfig($agent);

        $resolver = $this->principalResolver ?? new PrincipalResolver();
        $principalContext = $resolver->resolveForToolExecute($agent->id);

        $request = new LLMRequest(
            systemPrompt: $this->resolveSystemPrompt($agent),
            messages: $this->orchestrator->buildMessages($task->id),
            tools: $this->orchestrator->toolDefinitionBuilder->buildToolDefinitions($enabledClasses, $agent->id, $principalContext),
            contextWindow: $llmConfig['context_window'],
            maxTokens: $llmConfig['max_tokens_output'],
            temperature: $llmConfig['temperature'],
        );

        return [
            'task'            => $task,
            'agent'           => $agent,
            'enabledClasses'  => $enabledClasses,
            'contextWindow'   => $llmConfig['context_window'],
            'maxTokensOutput' => $llmConfig['max_tokens_output'],
            'request'         => $request,
        ];
    }

    private function resolveSystemPrompt(Agent $agent): string
    {
        return ($agent->system_prompt !== null && $agent->system_prompt !== '')
            ? $agent->system_prompt
            : 'You are a helpful AI assistant.';
    }

    private function dispatchLlmRequest(array $context): LLMResponse
    {
        return $this->driverFactory
            ->makeFromAgent($context['agent'])
            ->complete($context['request']);
    }

    /**
     * @param array{
     *   task: Task,
     *   agent: Agent
     * } $context
     */
    private function handleTickLlmResponse(array $context, LLMResponse $response): void
    {
        $task  = $context['task'];
        $agent = $context['agent'];

        if ($response->hasToolCalls()) {
            $this->recordAssistantToolCallBatch($task, $response);
            $this->handleToolCalls($task, $agent, $response->toolCalls);
            return;
        }

        $this->completeTaskWithResponse($task, $response);
    }

    private function recordAssistantToolCallBatch(Task $task, LLMResponse $response): void
    {
        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'assistant',
            content: null,
            context: new HistoryMessageContext(
                toolCallPayload: json_encode(
                    array_map(static fn(DriverToolCall $tc) => [
                        'id' => $tc->providerCallId,
                        'type' => 'function',
                        'function' => [
                            'name' => $tc->toolName,
                            // Normalize empty array [] to {} for strict providers
                            'arguments' => empty($tc->arguments) ? '{}' : json_encode($tc->arguments, JSON_THROW_ON_ERROR),
                        ],
                    ], $response->toolCalls),
                    JSON_THROW_ON_ERROR,
                ),
                inputTokens: $response->usage->inputTokens,
                outputTokens: $response->usage->outputTokens,
                contentBlocks: $response->contentBlocks,
                usage: $response->usage,
            ),
        );
    }

    private function completeTaskWithResponse(Task $task, LLMResponse $response): void
    {
        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'assistant',
            content: $response->content,
            context: new HistoryMessageContext(
                inputTokens: $response->usage->inputTokens,
                outputTokens: $response->usage->outputTokens,
                contentBlocks: $response->contentBlocks,
                usage: $response->usage,
            ),
        );

        $task->status         = 'COMPLETED';
        $task->final_response = Utf8Sanitizer::scrubString($response->content);
        $task->save();

        if (!isset($task->data['run_id'])) {
            $this->notificationService?->notifyTaskCompleted($task);
        }

        // If this task was a child of a parent waiting for sub-agents,
        // check whether the parent is ready to resume now that every
        // sibling has also terminated.
        $this->maybeResumeParentForChild($task->id);
    }

    /**
     * @param array{
     *   task: Task,
     *   agent: Agent
     * } $context
     */
    private function handleTickFailure(int $taskId, array $context, Throwable $e): void
    {
        $this->logger?->error('tick() failed', [
            'task_id'         => $taskId,
            'exception_class' => get_class($e),
            'message'         => $e->getMessage(),
        ]);

        if ($this->orchestrator->errorClassifier->isContextWindowError($e)) {
            $this->orchestrator->contextWindowRecovery->tryCompactionAndRetry($context['task'], $context['agent'], $e);
            return;
        }

        $errorCode = $this->orchestrator->errorClassifier->classifyError($e);
        $friendlyMsg = $this->orchestrator->errorClassifier->friendlyMessageForError($e, $errorCode);

        try {
            $updated = Task::where('id', $taskId)
                ->where('status', 'RUNNING')
                ->update([
                    'status'         => 'FAILED',
                    'failure_reason' => Utf8Sanitizer::scrubString($e->getMessage()),
                    'error_code'     => $errorCode,
                    'error_message'  => Utf8Sanitizer::scrubString($friendlyMsg),
                ]);

            if ($updated > 0) {
                $failedTask = Task::where('id', $taskId)->first();
                if ($failedTask !== null) {
                    $this->notifyFailedAndScheduleRetry($failedTask, $errorCode);
                }

                // A child task that failed still counts as "terminal" for the
                // parent's wait — the parent will resume with the failure
                // message as the tool result.
                $this->maybeResumeParentForChild($taskId);
            }
        } catch (Throwable) {
            // Ignore failure — DB itself may be unavailable.
        }

        throw $e;
    }

    private function notifyFailedAndScheduleRetry(Task $failedTask, string $errorCode): void
    {
        try {
            $this->notificationService?->notifyTaskFailed($failedTask);
        } catch (Throwable $e) {
            $this->logger?->warning('Notification failed', [
                'task_id'   => $failedTask->id,
                'exception' => $e->getMessage(),
            ]);
        }

        try {
            $this->orchestrator->retryScheduler->scheduleAutoRetry($failedTask, $errorCode);
        } catch (Throwable $e) {
            $this->logger?->warning('Auto-retry scheduling failed', [
                'task_id'   => $failedTask->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function handleToolCalls(Task $task, Agent $agent, array $toolCalls): void
    {
        /** @var list<DriverToolCall> $pendingApproval */
        $pendingApproval = [];

        foreach ($toolCalls as $toolCall) {
            try {
                $disposition = $this->orchestrator->toolCallExecutor->executeOrQueue($toolCall, $agent, $task);

                if ($disposition === ToolCallDisposition::AwaitingApproval) {
                    $pendingApproval[] = $toolCall;
                }
            } catch (ToolNotEnabledException $e) {
                // Authorization drift: the LLM proposed a tool that is no longer
                // (or never was) in this agent's allowed set. Surface it as an
                // explicit authorization message so the LLM doesn't try again on
                // its next turn — the next tick will also rebuild the tool list
                // via {@see prepareTickContext()}, but the LLM needs the in-band
                // signal so it doesn't waste a round-trip rediscovering it.
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

        if ($pendingApproval === []) {
            // Abort-bail: a user abort could have landed between this tick's
            // claim and the completion of the tool batch. We accept the user's
            // request up to this tool boundary — once the latest tool
            // returned, we re-read the status before either kicking the next
            // tick or handing the loop off to the parent-resume hook. If the
            // row is `ABORTED`, no further LLM traffic happens this tick.
            //
            // Publish the just-completed tool output BEFORE the bail: the
            // chat relies on Mercure for live tool output. If we published
            // only on the next tick (which never arrives for an aborted
            // task), the user would have to reload the page to see the
            // tool result that landed the same instant they clicked Abort.
            //
            // Re-read the row before publishing so the payload reflects
            // the current DB state — passing the in-memory $task here
            // would carry the stale RUNNING status into the Mercure
            // event when the row had already been flipped to ABORTED.
            $latestStatus = Task::where('id', $task->id)->value('status');
            $this->publishIntermediateState(Task::find($task->id) ?? $task);
            if ($latestStatus === 'ABORTED') {
                $this->logger?->info('Tick bailed — task was aborted after tool batch', [
                    'task_id' => $task->id,
                ]);
                return;
            }

            // Sync-mode auto-approve batch boundary: every tool in this turn ran
            // inline (no ApprovedBatchExecutor involved), so the resume hook in
            // ApprovedBatchExecutor::triggerBatchBoundaryResume never fires for
            // this path. Mirror it here so any spawned sub_agents get a chance
            // to wake their parent up at the end of the turn. The worker-mode
            // equivalent lives in executeApprovedPendingToolsForTask() above.
            $this->maybeResumeParentFromBatchBoundary($task->id);

            // Re-check after the batch-boundary hook — a parent that flipped
            // to ABORTED through {@see TaskService::abortSubAgentAndCascade}
            // must not start another LLM turn.
            $latestStatus = Task::where('id', $task->id)->value('status');
            if ($latestStatus === 'ABORTED') {
                return;
            }

            // Before recursive tick — keep the lease alive across the next
            // tool batch + LLM round-trip so the reaper does not flip the
            // row mid-batch.
            $this->leaseGuard->extend($task->id);

            if ($this->singleStep) {
                // Client-worker mode: stop after one LLM turn so the SPA
                // sees this batch of tool calls. Flip status back to
                // QUEUED so the browser's next /tick can CAS-claim the
                // row (the orchestrator's claim path rejects rows that
                // are still RUNNING, which is where the outer tick left
                // them). Clear the lease so the reaper doesn't pick up
                // the row while the browser is preparing the next tick —
                // the browser re-claims it with its own lease_owner.
                Task::where('id', $task->id)
                    ->where('status', 'RUNNING')
                    ->update([
                        'status'           => 'QUEUED',
                        'lease_owner'      => null,
                        'lease_expires_at' => null,
                    ]);
                $this->publishIntermediateState(Task::find($task->id) ?? $task);
                return;
            }

            $this->orchestrator->tick($task->id);
        } else {
            $state = new AgentState(
                taskId: $task->id,
                agentId: $agent->id,
                pendingToolCalls: $pendingApproval,
                messageSnapshot: $this->orchestrator->buildMessages($task->id),
                stepCount: $task->step_count,
                maxSteps: $task->max_steps,
                pausedAt: date('Y-m-d\TH:i:s\Z'),
            );

            $task->status        = 'PENDING_APPROVAL';
            $task->pending_state = $state->toJson();
            $task->save();

            $toolNames = implode(', ', array_unique(array_map(
                static fn(DriverToolCall $tc) => $tc->toolName,
                $pendingApproval,
            )));
            $this->logger?->info('Task paused — approval needed', [
                'task_id' => $task->id,
                'tool_count' => count($pendingApproval),
                'tools' => $toolNames,
            ]);

            $this->notificationService?->notifyPendingApproval($task);

            $this->publishIntermediateState($task);
        }
    }

    private function publishIntermediateState(Task $task): void
    {
        if ($this->mercure === null) {
            return;
        }

        $serializer = $this->toolCallSerializer ?? new ToolCallSerializer($this->toolInstances);

        $historyRows = $task->taskHistory()->orderBy('sequence')->get();
        $historyPayload = \Spora\Services\TaskHistorySerializer::buildHistoryPayload($historyRows);
        $totals = \Spora\Services\TaskHistorySerializer::aggregateUsage($historyPayload['usages']);

        $taskData = [
            'id' => $task->id,
            'status' => $task->status,
            'step_count' => $task->step_count,
            'tool_calls' => $task->toolCalls->map(fn(ToolCallModel $tc) => $serializer->toArray($tc))->all(),
            'history' => $historyPayload['history'],
            'totals' => $totals,
        ];

        $this->mercure->publish($task->id, $task->user_id, $taskData);
    }

    /**
     * Wrap the SubAgentService hook so existing call sites stay one-line.
     * Skips silently when DI wasn't wired (e.g. lightweight test harnesses
     * that don't construct SubAgentService).
     */
    private function maybeResumeParentForChild(int $childTaskId): void
    {
        if ($this->subAgent === null) {
            return;
        }

        try {
            $this->subAgent->maybeResumeParent($childTaskId);
        } catch (Throwable $e) {
            $this->logger?->warning('SubAgentService::maybeResumeParent failed', [
                'task_id'   => $childTaskId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

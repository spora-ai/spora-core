<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Spora\Agents\Exceptions\InvalidTaskTransitionException;
use Spora\Agents\Exceptions\TaskStateMissingException;
use Spora\Agents\Exceptions\ToolContractException;
use Spora\Agents\Exceptions\ToolNotRegisteredException;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Auth\AuthService;
use Spora\Drivers\DriverFactory;
use Spora\Models\Agent;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Plugins\PluginLoader;
use Spora\Services\AgentServiceInterface;
use Spora\Services\LLMConfigService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\ScrubDataUrls;
use Spora\Services\SubAgentServiceInterface;
use Spora\Services\Text\Utf8Sanitizer;
use Spora\Services\ToolCallSerializer;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

final class Orchestrator implements OrchestratorInterface
{
    /** Format used when writing UTC wall-clock timestamps to the DB. */
    public const DB_TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /** ISO 8601 / RFC 3339 format used for the AgentState `pausedAt` field. */
    public const ISO8601_UTC_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** Package-private extracted services; read by `TickPhaseRunner` and the other extracted services via the orchestrator. */
    public readonly DriverFactory $driverFactory;
    public readonly ErrorClassifier $errorClassifier;
    public readonly ToolDefinitionBuilder $toolDefinitionBuilder;
    public readonly LlmConfigResolver $llmConfigResolver;
    public readonly RetryScheduler $retryScheduler;
    public readonly ContextWindowRecovery $contextWindowRecovery;
    public readonly ApprovedBatchExecutor $approvedBatchExecutor;
    public readonly TickPhaseRunner $tickPhaseRunner;
    public readonly ToolCallExecutor $toolCallExecutor;
    public readonly AgentDecisionProcessor $agentDecisionProcessor;
    public readonly AgentStateResolver $agentStateResolver;
    public readonly TaskStatusWriter $statusWriter;

    /** Lease config threaded through recursive ticks within the same
     *  HTTP request (or worker daemon). Set by the outermost `tick()`
     *  call so child calls inherit the same lease without each level
     *  needing to re-pass the config. Cleared in a finally block so an
     *  exception does not leak the config into the next request. */
    private ?OrchestratorConfig $currentTickConfig = null;

    /** @var list<object> */
    public readonly array $toolInstances;
    public readonly ?LoggerInterface $logger;
    public readonly ?NotificationService $notificationService;
    public readonly ?MercurePublisherInterface $mercure;
    public readonly ?ToolConfigService $toolConfigService;
    public readonly ?ToolCallSerializer $toolCallSerializer;
    public readonly ?LLMConfigService $llmConfigService;
    public readonly ?PluginLoader $pluginLoader;
    public readonly ?AgentServiceInterface $agentService;
    public readonly ?SubAgentServiceInterface $subAgent;
    public readonly ?PrincipalResolver $principalResolver;
    public readonly ?AuthService $authService;

    public function __construct(
        DriverFactory $driverFactory,
        ?OrchestratorConfig $config = null,
        ?PrincipalResolver $principalResolver = null,
        ?AuthService $authService = null,
    ) {
        $config ??= new OrchestratorConfig();

        $this->toolInstances         = $config->toolInstances;
        $this->logger                = $config->logger;
        $this->notificationService   = $config->notificationService;
        $this->mercure               = $config->mercure;
        $this->toolConfigService     = $config->toolConfigService;
        $this->toolCallSerializer    = $config->toolCallSerializer;
        $this->llmConfigService      = $config->llmConfigService;
        $this->pluginLoader          = $config->pluginLoader;
        $this->agentService          = $config->agentService;
        $this->subAgent              = $config->subAgent;
        $this->principalResolver     = $principalResolver ?? new PrincipalResolver();
        $this->authService           = $authService;
        $this->driverFactory         = $driverFactory;
        $this->errorClassifier       = new ErrorClassifier();
        $this->llmConfigResolver     = new LlmConfigResolver(
            $config->principalPreferences ?? new \Spora\Services\LLMConfigPreferences(),
            $config->llmConfigService,
        );
        $this->toolDefinitionBuilder = new ToolDefinitionBuilder(
            $config->toolInstances,
            $config->toolConfigService,
            $config->pluginLoader,
            fn(array $llmSettings): string => $this->buildLlmConfigBlock($llmSettings),
        );
        $this->retryScheduler        = new RetryScheduler($config->logger, $config->notificationService);
        $this->contextWindowRecovery = new ContextWindowRecovery($this, $driverFactory, $config->logger, $config->notificationService);
        $this->approvedBatchExecutor = new ApprovedBatchExecutor($this, $config->logger);
        $this->tickPhaseRunner       = new TickPhaseRunner(
            $this,
            $driverFactory,
            $config->toolInstances,
            $config->logger,
            $config->notificationService,
            $config->mercure,
            $config->toolCallSerializer,
            $config->subAgent,
            $principalResolver ?? new PrincipalResolver(),
        );
        $this->toolCallExecutor      = new ToolCallExecutor($this);
        $this->agentDecisionProcessor = new AgentDecisionProcessor($this);
        $this->agentStateResolver     = new AgentStateResolver($this);
        $this->statusWriter           = new TaskStatusWriter();
    }

    // Public API

    public function start(int $agentId, string $userPrompt, int $maxSteps = 10, ?int $parentTaskId = null, ?int $runId = null, array $mediaIds = [], ?int $userId = null): Task
    {
        Agent::findOrFail($agentId);

        $taskData = $runId !== null ? ['run_id' => $runId] : [];

        // `tasks.user_id` has two meanings:
        // - task attribution (who triggered the chat) → used by the UI to
        //   decide which tasks show up under "My tasks" and to gate
        //   per-task actions like approve / reject / abort / destroy
        // - credential ownership → used by the orchestrator to pick whose
        //   LLM driver config + tool overrides to load on each tick
        // For interactive callers (`POST /tasks`) the HTTP controller knows
        // exactly who triggered the request, so it passes `$userId` and we
        // skip the runner fallback. Worker / scheduled-run paths leave it
        // null and fall through to the runner (most-recent user) so the
        // LLM still runs under a valid principal.
        $resolvedUserId = $userId;
        if ($resolvedUserId === null) {
            $resolver = $this->principalResolver ?? new PrincipalResolver();
            $resolvedUserId = $resolver->runnerUserId($agentId) ?? $this->authService?->currentUserId();
        }

        $task = Task::create([
            'agent_id'      => $agentId,
            'user_id'       => $resolvedUserId,
            'status'        => 'QUEUED',
            'user_prompt'   => Utf8Sanitizer::scrubString($userPrompt),
            'step_count'    => 0,
            'max_steps'     => $maxSteps,
            'parent_task_id' => $parentTaskId,
            'data'          => $taskData,
        ]);

        $this->appendHistory($task->id, 'user', $userPrompt);

        if ($mediaIds !== []) {
            $this->appendAttachmentRow($task->id, $mediaIds);
        }

        return $task->fresh();
    }

    /**
     * Resumes a task from a non-terminal state. Accepted source states:
     *
     *   - `RUNNING`           — auto-abort: flip to `ABORTED`, persist
     *                            the wall-clock stamp, append an abort-marker
     *                            system row, append the user's prompt, and
     *                            return WITHOUT calling `tick()`. The next
     *                            resume (with a fresh prompt) is what
     *                            actually drives the LLM. Done atomically
     *                            inside a `lockForUpdate` transaction so a
     *                            racing tick cannot observe a half-applied
     *                            state.
     *   - `ABORTED`           — drop `data.aborted_at`, flip to
     *                            `RUNNING`/`QUEUED`, append the user's
     *                            prompt, then either tick (Sync) or queue
     *                            (Worker).
     *   - `COMPLETED`/`FAILED` — append the user's prompt, flip to
     *                            `RUNNING`/`QUEUED`, tick or queue.
     *
     * Throws {@see InvalidTaskTransitionException} for any other source
     * state. The marker row carries `role=system`, `content=JSON`
     * `{"kind":"abort_marker","at":<UTC>}` so the frontend can render
     * a faint divider at the marker position in the chat timeline.
     */
    public function continue(int $taskId, string $newPrompt, ?int $additionalSteps = null, array $mediaIds = []): Task
    {
        $sourceStatus = null;
        $taskRef = null;

        Capsule::connection()->transaction(function () use ($taskId, $newPrompt, $additionalSteps, $mediaIds, &$sourceStatus, &$taskRef): void {
            $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();
            $sourceStatus = $task->status;

            if (!(new TaskLifecyclePolicy())->canContinueFrom($task->status)) {
                throw new InvalidTaskTransitionException(
                    (new TaskLifecyclePolicy())->incomingSourceErrorMessage(),
                );
            }

            if ($task->status === 'RUNNING') {
                $this->appendHistoryWithinTransaction(
                    taskId: $task->id,
                    role: 'system',
                    content: json_encode(
                        ['kind' => 'abort_marker', 'at' => gmdate(Orchestrator::ISO8601_UTC_FORMAT)],
                        JSON_THROW_ON_ERROR,
                    ),
                );
                $this->appendHistoryWithinTransaction(
                    taskId: $task->id,
                    role: 'user',
                    content: Utf8Sanitizer::scrubString($newPrompt),
                );

                if ($mediaIds !== []) {
                    $this->appendAttachmentRow($task->id, $mediaIds);
                }

                $taskRef = $this->statusWriter->applyContinueTransition($task, $newPrompt, $additionalSteps, targetStatus: 'ABORTED', clearAbortedAt: false);
                return;
            }

            $this->appendHistoryWithinTransaction(
                taskId: $task->id,
                role: 'user',
                content: Utf8Sanitizer::scrubString($newPrompt),
            );

            if ($mediaIds !== []) {
                $this->appendAttachmentRow($task->id, $mediaIds);
            }

            $taskRef = $this->statusWriter->applyContinueTransition(
                $task,
                $newPrompt,
                $additionalSteps,
                targetStatus: 'QUEUED',
                clearAbortedAt: $task->status === 'ABORTED',
            );
        });

        if ($sourceStatus === 'RUNNING') {
            $this->logger?->info('Task auto-aborted via continue', [
                'task_id' => $taskId,
                'from'    => 'RUNNING',
                'to'      => 'ABORTED',
                'user_id' => $taskRef?->user_id,
            ]);
        }

        return $taskRef !== null ? $taskRef->fresh() : Task::findOrFail($taskId);
    }

    /**
     * Same persist logic as {@see appendHistory()} but invoked from
     * inside an open transaction so callers can compose history inserts
     * with status updates atomically. Used by {@see continue()} for the
     * RUNNING → ABORTED auto-abort path, where the marker row and the
     * status flip must commit together.
     */
    private function appendHistoryWithinTransaction(
        int                    $taskId,
        string                 $role,
        ?string                $content,
        ?HistoryMessageContext $context = null,
    ): void {
        $context ??= new HistoryMessageContext();

        $row = HistoryRowWriter::buildRow($taskId, $role, $content, $context);

        $nextSeq = TaskHistory::where('task_id', $taskId)->max('sequence') ?? -1;
        $row['sequence'] = $nextSeq + 1;
        $history = TaskHistory::create($row);

        HistoryRowWriter::insertUsageIfPresent($history->id, $context->usage);
    }

    /**
     * Re-run a failed task in place: same task_id, same URL, full conversation
     * history preserved as LLM context. The LLM sees the original user prompt
     * plus any failed assistant/tool rows from the previous attempt, which lets
     * it either retry the failing call or take an alternative path on transient
     * errors (rate limit, timeout, gateway blip).
     *
     * Resets `error_code`, `failure_reason`, `retry_after`, `step_count`,
     * `retry_of_task_id`, and `status` (FAILED → RUNNING in Sync mode, FAILED →
     * QUEUED in Async mode). `max_steps` is preserved. No existing history
     * rows are rewritten; any new rows produced on this re-run come from the
     * underlying tick, not from retry() itself. `retry_of_task_id` is cleared
     * so the task becomes claimable by WorkerQueueProcessor's main QUEUED
     * loop — the chain "ends" as soon as the worker picks the row up, and a
     * subsequent failure (if any) re-arms it via TickPhaseRunner::
     * notifyFailedAndScheduleRetry.
     */
    public function retry(int $taskId): Task
    {
        $task = Task::findOrFail($taskId);

        if ($task->status !== 'FAILED') {
            throw new InvalidTaskTransitionException('Can only retry failed tasks.');
        }

        $task->status = 'QUEUED';
        $task->step_count = 0;
        $task->error_code = null;
        $task->failure_reason = null;
        $task->retry_after = null;
        $task->retry_of_task_id = null;
        $task->save();

        return $task->fresh();
    }

    /**
     * Halts the running agent loop. Acquires `lockForUpdate` row lock;
     * flips status to `ABORTED` and stamps `data.aborted_at`. Idempotent
     * on already-`ABORTED` (silent, no log). Throws
     * {@see InvalidTaskTransitionException} for source states that
     * aren't `RUNNING` or `AWAITING_SUB_AGENTS`. Emits structured log
     * (`task_id`, `user_id`, `from`, `to`, `source`) only when the row
     * was actually transitioned.
     */
    public function abort(int $taskId): Task
    {
        $policy = new TaskLifecyclePolicy();
        $source = null;
        $userId = null;
        $didWrite = false;

        Capsule::connection()->transaction(function () use ($taskId, $policy, &$source, &$userId, &$didWrite): void {
            $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();
            $source = $task->status;

            if ($task->status === 'ABORTED') {
                return;
            }

            $userId = (int) $task->user_id;

            if (!$policy->canAbortFrom($task->status)) {
                throw new InvalidTaskTransitionException(
                    "Cannot abort a task in status {$task->status}.",
                );
            }

            $this->statusWriter->abortTransition($task);
            $didWrite = true;
        });

        if ($didWrite && $userId !== null) {
            $this->logger?->info('Task aborted', [
                'task_id' => $taskId,
                'user_id' => $userId,
                'from'    => $source,
                'to'      => 'ABORTED',
                'source'  => 'user_abort',
            ]);
        }

        return Task::findOrFail($taskId);
    }

    /**
     * Resolve media IDs to asset rows (with ownership check) and write
     * an `attachment` row to the task history. The MessageHistoryBuilder
     * expands this row into content blocks.
     *
     * @param list<string> $mediaIds
     */
    private function appendAttachmentRow(int $taskId, array $mediaIds): void
    {
        $userId = (int) (Task::find($taskId)?->user_id ?: 0);
        $refs = [];
        foreach ($mediaIds as $mid) {
            if ($mid === '') {
                continue;
            }
            $asset = \Spora\Models\MediaAsset::query()->find($mid);
            if ($asset === null) {
                continue;
            }
            if ($asset->user_id !== null && $userId !== 0 && (int) $asset->user_id !== $userId) {
                throw new \Spora\Services\MediaArchive\MediaNotOwnedException(
                    "Media asset {$mid} is not owned by the current user.",
                );
            }
            $kind = str_starts_with((string) $asset->mime_type, 'image/') ? 'image' : 'text';
            $refs[] = ['media_id' => $asset->id, 'kind' => $kind];
        }
        if ($refs === []) {
            return;
        }
        $this->appendHistory(
            $taskId,
            'attachment',
            '',
            new HistoryMessageContext(attachments: $refs),
        );
    }

    public function tick(int $taskId, ?OrchestratorConfig $config = null): void
    {
        // Track whether THIS call owns the currentTickConfig — the
        // outermost caller is the only one allowed to clear it in the
        // finally block. A recursive tick (called from TickPhaseRunner
        // during handleToolCalls) inherits the stored config and must
        // NOT clear it on exit, otherwise the outer tick's lease would
        // vanish mid-batch.
        $ownedConfig = $config !== null;
        if ($ownedConfig) {
            $this->currentTickConfig = $config;
        } else {
            $config = $this->currentTickConfig;
        }

        $leaseOwner = $config?->leaseOwner;
        $leaseSeconds = $config !== null ? $config->tickLeaseSeconds : 600;
        $this->tickPhaseRunner->setLeaseConfig($leaseOwner, $leaseSeconds);

        try {
            $this->tickPhaseRunner->runTick($taskId);
        } finally {
            if ($ownedConfig) {
                $this->currentTickConfig = null;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function resume(int $taskId, array $decisions): void
    {
        Capsule::connection()->transaction(function () use ($taskId, $decisions): void {
            $task = $this->agentStateResolver->loadPendingTask($taskId);
            $state = $this->agentStateResolver->loadAgentState($task);

            [$approvedBatch, $rejectedBatch] = $this->agentDecisionProcessor->splitDecisions($decisions, $state);
            $remainingState = $this->agentStateResolver->buildRemainingAgentState($state, $rejectedBatch);

            $this->agentDecisionProcessor->markRejectionBatch($task, $rejectedBatch);

            $this->agentStateResolver->applyResumeTransition($task, $approvedBatch, $remainingState);

            if ($approvedBatch !== []) {
                $this->approvedBatchExecutor->execute($taskId, $approvedBatch);
            }
        });
    }

    public function reject(int $taskId, string $reason): void
    {
        $task = null;
        $state = null;

        Capsule::connection()->transaction(function () use ($taskId, &$task, &$state) {
            $task = $this->agentStateResolver->loadPendingTask($taskId);
            $state = $this->agentStateResolver->loadAgentState($task);
            $task->pending_state = null;
            $task->save();
        });

        try {
            if (!$task instanceof Task || !$state instanceof AgentState) {
                throw new TaskStateMissingException('Failed to resolve task or state during reject.');
            }
            $this->agentStateResolver->recordBulkRejection($task, $reason);
            $this->agentStateResolver->transitionTaskAfterRejection($taskId);
        } catch (Throwable $e) {
            Task::where('id', $taskId)->update([
                'status'         => 'FAILED',
                'error_code'     => 'REJECT_FAILED',
                'error_message'  => Utf8Sanitizer::scrubString('Task reject failed: ' . $e->getMessage()),
                'failure_reason' => Utf8Sanitizer::scrubString($e->getMessage()),
            ]);
            throw $e;
        }
    }

    public function safeExecute(
        ToolInterface $toolInstance,
        array $arguments,
        int $agentId,
        int $taskId,
    ): ToolResult {
        $ref      = new ReflectionClass($toolInstance);
        $attrs    = $ref->getAttributes(Tool::class);
        $toolName = $attrs !== [] ? $attrs[0]->newInstance()->name : get_class($toolInstance);

        $resolver = $this->principalResolver ?? new PrincipalResolver();
        $context  = $resolver->resolveForToolExecute($agentId);

        // Source the calling user's id from the calling Agent's row
        // — tools never see a session-derived `$userId`. When the
        // orchestrator runs without AgentService (e.g. a minimal
        // boot-auth test harness), the tool's $userId stays null and
        // the tool's own getAgentByAgentId() fallback applies.
        $userId = null;
        if ($this->agentService !== null) {
            $callingAgent = $this->agentService->getAgentByAgentId($agentId);
            if ($callingAgent !== null) {
                $userId = $callingAgent->user_id;
            }
        }

        // Arguments may contain PII — never log them.
        $this->logger?->debug('Tool dispatch', [
            'tool'      => $toolName,
            'agent_id'  => $agentId,
            'user_id'   => $userId,
            'task_id'   => $taskId,
            'arguments' => $arguments,
        ]);

        try {
            $result = $toolInstance->execute($arguments, $agentId, $userId, $taskId, $context);

            if (!$result->success) {
                $this->logger?->error('Tool returned failure', [
                    'tool'     => $toolName,
                    'agent_id' => $agentId,
                    'task_id'  => $taskId,
                    'content'  => $result->content,
                ]);
            }

            return $result;
        } catch (Throwable $e) {
            $this->logger?->error('Tool threw exception', [
                'tool'            => $toolName,
                'agent_id'        => $agentId,
                'task_id'         => $taskId,
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]);

            return new ToolResult(
                success: false,
                content: 'System Error: The tool encountered a fatal exception: ' . $e->getMessage(),
                data: ['exception_class' => get_class($e), 'trace' => $e->getTraceAsString()],
            );
        }
    }

    public function resolveRequiresApproval(object $toolInstance, string $toolClass, int $agentId, array|object $arguments = []): bool
    {
        if (is_object($arguments)) {
            $arguments = (array) $arguments;
        }

        $usesOperations = in_array(HasOperations::class, class_uses_recursive($toolClass), true);

        if ($usesOperations) {
            $operationName = $toolInstance->getOperationName($arguments);

            // Approval resolution is per-operation only. The UI exposes a
            // per-operation auto-approve toggle (no agent-wide toggle for
            // tools that have #[ToolOperation] declarations — every current
            // tool qualifies). Precedence:
            //
            //   1. Per-op override (#[AgentToolOperationOverride] row) wins.
            //   2. Otherwise the operation's #[ToolOperation(requiresApprovalByDefault:)]
            //      class default wins.
            /** @var AgentToolOperationOverride|null $override */
            $override = AgentToolOperationOverride::where('agent_id', $agentId)
                ->where('tool_class', $toolClass)
                ->where('operation', $operationName)
                ->first();

            if ($override !== null) {
                $raw = $override->getRawOriginal('default_requires_approval');
                if ($raw !== null) {
                    return (bool) $raw; // 1 = approval required → true, 0 = auto-approve → false
                }
            }

            return $toolInstance->requiresApprovalByDefault($operationName);
        }

        throw new ToolContractException("Tool '{$toolClass}' does not use HasOperations trait.");
    }

    public function isOperationEnabled(object $toolInstance, string $operationName, int $agentId): bool
    {
        $toolClass = get_class($toolInstance);

        /** @var AgentToolOperationOverride|null $override */
        $override = AgentToolOperationOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->where('operation', $operationName)
            ->first();

        if ($override !== null) {
            $raw = $override->getRawOriginal('enabled');
            if ($raw !== null) {
                return (bool) $raw;
            }
        }

        return $toolInstance->isEnabledByDefault($operationName);
    }

    /**
     * Stamp a `tool_calls` row REJECTED for a tool that the agent has been
     * revoked from or had a specific op disabled, and append a `'tool'`
     * history message carrying the cause. Shared by both resume paths
     * (`ApprovedBatchExecutor`, `TickPhaseRunner`) so the disposition shape
     * stays consistent across sync and worker modes.
     *
     * `rejected_by` is left null: revocation is an admin/system action with
     * no single user-actor in this column's model. The reason lives in
     * `reject_reason` and in the appended history row, which is what the
     * LLM sees on its next round-trip.
     */
    public function recordRevokedToolCall(
        Task   $task,
        string $providerCallId,
        string $toolName,
        string $rejectReason,
    ): void {
        ToolCallModel::where('task_id', $task->id)
            ->where('provider_call_id', $providerCallId)
            ->update([
                'status'        => 'REJECTED',
                'rejected_at'   => date(self::DB_TIMESTAMP_FORMAT),
                'rejected_by'   => null,
                'reject_reason' => $rejectReason,
            ]);

        $this->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub(Utf8Sanitizer::scrubString("Action rejected: {$rejectReason}")),
            context: new HistoryMessageContext(
                toolCallId: $providerCallId,
                toolName: $toolName,
            ),
        );
    }

    public function buildMessages(int $taskId): array
    {
        $driver = null;
        $task = Task::find($taskId);
        if ($task !== null && $task->agent_id) {
            try {
                $driver = $this->driverFactory->makeFromAgent(Agent::findOrFail($task->agent_id));
            } catch (Throwable) {
                $driver = null;
            }
        }
        return (new MessageHistoryBuilder($driver))->build($taskId);
    }

    /**
     * Thin wrapper kept on the orchestrator so the existing test suite
     * can call it via reflection. Delegates to {@see ToolDefinitionBuilder}.
     *
     * @param  list<string>  $enabledClasses
     * @return list<array<string, mixed>>
     */
    /** @phpstan-ignore method.unused (used via reflection in tests) */
    private function buildToolDefinitions(array $enabledClasses, int $agentId, ?PrincipalContext $context = null): array
    {
        return $this->toolDefinitionBuilder->buildToolDefinitions($enabledClasses, $agentId, $context);
    }

    private function buildLlmConfigBlock(array $llmSettings): string
    {
        if ($llmSettings === []) {
            return '';
        }

        $lines = [];
        foreach ($llmSettings as $setting) {
            $value = $setting['value'];
            if ($value === null || $value === '' || $value === []) {
                $display = '(not configured)';
            } elseif (is_array($value)) {
                // list<string> from a multi-select, or a list of agent "Name (#id)" pairs.
                $display = implode(', ', array_map(static fn($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
            } else {
                $display = (string) $value;
            }
            $lines[] = '- ' . $setting['label'] . ': ' . $display;
        }

        return "\n[Effective Configuration]\n" . implode("\n", $lines);
    }

    public function callTraitMethod(object $object, string $method, array $args): mixed
    {
        /** @var callable */
        $callable = [$object, $method];
        return $callable(...$args);
    }

    public function resolveToolByName(string $toolName): ToolInterface
    {
        // Strip plugin slug prefix if present (e.g. "my-plugin:web_search" → "web_search").
        $plainName = $toolName;
        if (str_contains($toolName, ':')) {
            $plainName = substr($toolName, strpos($toolName, ':') + 1);
        }

        foreach ($this->toolInstances as $instance) {
            $ref   = new ReflectionClass($instance);
            $attrs = $ref->getAttributes(Tool::class);

            if ($attrs === []) {
                continue;
            }

            /** @var Tool $toolAttr */
            $toolAttr = $attrs[0]->newInstance();

            if ($toolAttr->name === $plainName) {
                return $instance;
            }
        }

        throw new ToolNotRegisteredException("No tool registered with name '{$toolName}'.");
    }

    public function appendHistory(
        int                       $taskId,
        string                    $role,
        ?string                   $content,
        ?HistoryMessageContext    $context = null,
    ): void {
        $context ??= new HistoryMessageContext();
        $row = HistoryRowWriter::buildRow($taskId, $role, $content, $context);
        $usage = $context->usage;

        Capsule::connection()->transaction(function () use ($taskId, $row, $usage): void {
            $nextSeq = TaskHistory::where('task_id', $taskId)->lockForUpdate()->max('sequence') ?? -1;
            $row['sequence'] = $nextSeq + 1;
            $history = TaskHistory::create($row);

            HistoryRowWriter::insertUsageIfPresent($history->id, $usage);
        });
    }
}

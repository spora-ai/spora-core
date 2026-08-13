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
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\DriverFactory;
use Spora\Models\Agent;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Plugins\PluginLoader;
use Spora\Services\AgentServiceInterface;
use Spora\Services\LLMConfigService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
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
    public readonly WorkerMode $workerMode;

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

    public function __construct(
        DriverFactory $driverFactory,
        ?OrchestratorConfig $config = null,
    ) {
        $config ??= new OrchestratorConfig();

        $this->workerMode            = $config->workerMode;
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
        $this->driverFactory         = $driverFactory;
        $this->errorClassifier       = new ErrorClassifier();
        $this->llmConfigResolver     = new LlmConfigResolver($config->llmConfigService);
        $this->toolDefinitionBuilder = new ToolDefinitionBuilder(
            $config->toolInstances,
            $config->toolConfigService,
            $config->pluginLoader,
            fn(array $llmSettings): string => $this->buildLlmConfigBlock($llmSettings),
        );
        $this->retryScheduler        = new RetryScheduler($config->logger, $config->notificationService);
        $this->contextWindowRecovery = new ContextWindowRecovery($this, $driverFactory, $config->logger, $config->notificationService);
        $this->approvedBatchExecutor = new ApprovedBatchExecutor($this, $config->workerMode, $config->logger);
        $this->tickPhaseRunner       = new TickPhaseRunner(
            $this,
            $driverFactory,
            $config->toolInstances,
            $config->logger,
            $config->notificationService,
            $config->mercure,
            $config->toolCallSerializer,
            $config->subAgent,
        );
        $this->toolCallExecutor      = new ToolCallExecutor($this);
        $this->agentDecisionProcessor = new AgentDecisionProcessor($this);
        $this->agentStateResolver     = new AgentStateResolver($this, $config->workerMode);
    }

    // Public API

    public function start(int $agentId, string $userPrompt, int $maxSteps = 10, ?int $parentTaskId = null, ?int $runId = null, array $mediaIds = []): Task
    {
        $agent = Agent::findOrFail($agentId);

        $taskData = $runId !== null ? ['run_id' => $runId] : [];

        $task = Task::create([
            'agent_id'      => $agentId,
            'user_id'       => $agent->user_id,
            'status'        => $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED',
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

        if ($this->workerMode === WorkerMode::Sync) {
            $this->tick($task->id);
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
     * state. The marker row carries `role=system`, `content=JSON`, and
     * `tool_call_payload.kind=abort_marker` so the frontend can render
     * a faint divider at the marker position in the chat timeline.
     */
    public function continue(int $taskId, string $newPrompt, ?int $additionalSteps = null, array $mediaIds = []): Task
    {
        $sourceStatus = null;
        $shouldTick = false;
        $taskRef = null;

        Capsule::connection()->transaction(function () use ($taskId, $newPrompt, $additionalSteps, $mediaIds, &$sourceStatus, &$shouldTick, &$taskRef): void {
            $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();
            $sourceStatus = $task->status;

            if (!(new TaskLifecyclePolicy())->canContinueFrom($task->status)) {
                throw new InvalidTaskTransitionException(
                    (new TaskLifecyclePolicy())->incomingSourceErrorMessage($task->status),
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

                $taskRef = $this->applyContinueTransition($task, $newPrompt, $additionalSteps, targetStatus: 'ABORTED', clearAbortedAt: false);
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

            $taskRef = $this->applyContinueTransition(
                $task,
                $newPrompt,
                $additionalSteps,
                targetStatus: $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED',
                clearAbortedAt: $task->status === 'ABORTED',
            );

            $shouldTick = $this->workerMode === WorkerMode::Sync;
        });

        if ($sourceStatus === 'RUNNING') {
            $this->logger?->info('Task auto-aborted via continue', [
                'task_id' => $taskId,
                'from'    => 'RUNNING',
                'to'      => 'ABORTED',
                'user_id' => $taskRef?->user_id,
            ]);
        }

        if ($shouldTick && $taskRef !== null) {
            $this->tick($taskRef->id);
        }

        return $taskRef !== null ? $taskRef->fresh() : Task::findOrFail($taskId);
    }

    /**
     * Persists the new status / step_count / user_prompt / optional
     * `max_steps` and `data` cleanup. Both branches of the relaxed
     * continue() share this so the field-update surface lives in one
     * place. Must be called from inside the outer transaction in
     * {@see continue()}.
     */
    private function applyContinueTransition(
        Task $task,
        string $newPrompt,
        ?int $additionalSteps,
        string $targetStatus,
        bool $clearAbortedAt,
    ): Task {
        $data = is_array($task->data) ? $task->data : [];

        if ($targetStatus === 'ABORTED') {
            $data['aborted_at'] = gmdate(self::DB_TIMESTAMP_FORMAT);
        } elseif ($clearAbortedAt) {
            unset($data['aborted_at']);
        }

        // Empty array → JSON null so the column is rewritten (clearing
        // any leftover keys) instead of silently leaving the old payload
        // in place.
        Capsule::table('tasks')
            ->where('id', $task->id)
            ->update([
                'status'      => $targetStatus,
                'step_count'  => 0,
                'user_prompt' => Utf8Sanitizer::scrubString($newPrompt),
                'max_steps'   => $additionalSteps !== null ? $additionalSteps : $task->max_steps,
                'data'        => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
                'updated_at'  => gmdate(self::DB_TIMESTAMP_FORMAT),
            ]);

        return Task::find($task->id);
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

        $row = [
            'task_id' => $taskId,
            'role' => $role,
            'content' => $content,
            'tool_call_id' => $context->toolCallId,
            'tool_name' => $context->toolName,
            'tool_call_payload' => $context->toolCallPayload,
            'input_tokens' => $context->inputTokens,
            'output_tokens' => $context->outputTokens,
        ];

        if ($context->contentBlocks !== []) {
            $row['content_blocks'] = array_map(
                static fn(\Spora\Drivers\ValueObjects\ContentBlock $block): array => $block->toArray(),
                $context->contentBlocks,
            );
        }

        if ($context->attachments !== null) {
            $row['attachments'] = $context->attachments;
        }

        $nextSeq = TaskHistory::where('task_id', $taskId)->max('sequence') ?? -1;
        $row['sequence'] = $nextSeq + 1;
        $history = TaskHistory::create($row);

        if ($context->usage !== null) {
            Capsule::table('usage')->insert([
                'task_history_id' => $history->id,
                'input_tokens' => $context->usage->inputTokens,
                'output_tokens' => $context->usage->outputTokens,
                'reasoning_tokens' => $context->usage->reasoningTokens,
                'cached_tokens' => $context->usage->cachedTokens,
                'cache_creation_tokens' => $context->usage->cacheCreationTokens,
                'cache_read_tokens' => $context->usage->cacheReadTokens,
                'provider' => $context->usage->provider,
                'raw_usage' => $context->usage->rawUsage === null
                    ? null
                    : json_encode($context->usage->rawUsage, JSON_THROW_ON_ERROR),
                'driver_meta_info' => $context->usage->driverMetaInfo === null
                    ? null
                    : json_encode($context->usage->driverMetaInfo, JSON_THROW_ON_ERROR),
                'created_at' => date(self::DB_TIMESTAMP_FORMAT),
            ]);
        }
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

        $task->status = $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED';
        $task->step_count = 0;
        $task->error_code = null;
        $task->failure_reason = null;
        $task->retry_after = null;
        $task->retry_of_task_id = null;
        $task->save();

        if ($this->workerMode === WorkerMode::Sync) {
            $this->tick($task->id);
        }

        return $task->fresh();
    }

    /**
     * Halts the running agent loop. Acquires a `lockForUpdate` row lock so
     * a racing tick cannot observe a half-applied status flip. Persists
     * the UTC `data.aborted_at` stamp and emits a structured log entry
     * (task_id, user_id, source_status, target_status, source). Idempotent
     * — calling on an already-`ABORTED` task returns the current row
     * without writing. Throws `InvalidTaskTransitionException` for source
     * states that aren't `RUNNING` or `AWAITING_SUB_AGENTS`.
     */
    public function abort(int $taskId): Task
    {
        $policy = new TaskLifecyclePolicy();
        $source = null;

        Capsule::connection()->transaction(function () use ($taskId, $policy, &$source): void {
            $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();
            $source = $task->status;

            if ($task->status === 'ABORTED') {
                return;
            }

            if (!$policy->canAbortFrom($task->status)) {
                throw new InvalidTaskTransitionException(
                    "Cannot abort a task in status {$task->status}.",
                );
            }

            $data = is_array($task->data) ? $task->data : [];
            $data['aborted_at'] = gmdate(self::DB_TIMESTAMP_FORMAT);

            Capsule::table('tasks')
                ->where('id', $task->id)
                ->update([
                    'status'     => 'ABORTED',
                    'data'       => json_encode($data, JSON_THROW_ON_ERROR),
                    'updated_at' => gmdate(self::DB_TIMESTAMP_FORMAT),
                ]);
        });

        $this->logger?->info('Task aborted', [
            'task_id' => $taskId,
            'from'    => $source,
            'to'      => 'ABORTED',
            'source'  => 'user_abort',
        ]);

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

    public function tick(int $taskId): void
    {
        $this->tickPhaseRunner->runTick($taskId);
    }

    /**
     * {@inheritDoc}
     */
    public function resume(int $taskId, array $decisions): void
    {
        $shouldTick = false;

        Capsule::connection()->transaction(function () use ($taskId, $decisions, &$shouldTick): void {
            $task = $this->agentStateResolver->loadPendingTask($taskId);
            $state = $this->agentStateResolver->loadAgentState($task);

            [$approvedBatch, $rejectedBatch] = $this->agentDecisionProcessor->splitDecisions($decisions, $state);
            $remainingState = $this->agentStateResolver->buildRemainingAgentState($state, $rejectedBatch);

            $this->agentDecisionProcessor->markRejectionBatch($task, $rejectedBatch);

            $shouldTick = $this->agentStateResolver->applyResumeTransition($task, $approvedBatch, $remainingState);

            if ($approvedBatch !== []) {
                $this->approvedBatchExecutor->execute($taskId, $approvedBatch);
                if ($this->workerMode === WorkerMode::Sync) {
                    $shouldTick = Task::where('id', $taskId)->value('status') === 'RUNNING';
                }
            }
        });

        if ($shouldTick) {
            $this->tick($taskId);
        }
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

        // Source the calling user's id from the calling Agent's row
        // — tools never see a session-derived `$userId`. When the
        // orchestrator runs without AgentService (e.g. a minimal
        // boot-auth test harness), the tool's $userId stays null and
        // the tool's own getAgentByAgentId() fallback applies.
        $userId = null;
        if ($this->agentService !== null) {
            $callingAgent = $this->agentService->getAgentByAgentId($agentId);
            if ($callingAgent !== null) {
                $userId = (int) $callingAgent->user_id;
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
            $result = $toolInstance->execute($arguments, $agentId, $userId, $taskId);

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
    private function buildToolDefinitions(array $enabledClasses, int $agentId, ?int $userId = null): array
    {
        return $this->toolDefinitionBuilder->buildToolDefinitions($enabledClasses, $agentId, $userId);
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

        $row = [
            'task_id' => $taskId,
            'role' => $role,
            'content' => $content,
            'tool_call_id' => $context->toolCallId,
            'tool_name' => $context->toolName,
            'tool_call_payload' => $context->toolCallPayload,
            'input_tokens' => $context->inputTokens,
            'output_tokens' => $context->outputTokens,
        ];

        if ($context->contentBlocks !== []) {
            $row['content_blocks'] = array_map(
                static fn(\Spora\Drivers\ValueObjects\ContentBlock $block): array => $block->toArray(),
                $context->contentBlocks,
            );
        }

        if ($context->attachments !== null) {
            $row['attachments'] = $context->attachments;
        }

        Capsule::connection()->transaction(function () use ($taskId, $row, $context): void {
            $nextSeq = TaskHistory::where('task_id', $taskId)->lockForUpdate()->max('sequence') ?? -1;
            $row['sequence'] = $nextSeq + 1;
            $history = TaskHistory::create($row);

            if ($context->usage !== null) {
                Capsule::table('usage')->insert([
                    'task_history_id' => $history->id,
                    'input_tokens' => $context->usage->inputTokens,
                    'output_tokens' => $context->usage->outputTokens,
                    'reasoning_tokens' => $context->usage->reasoningTokens,
                    'cached_tokens' => $context->usage->cachedTokens,
                    'cache_creation_tokens' => $context->usage->cacheCreationTokens,
                    'cache_read_tokens' => $context->usage->cacheReadTokens,
                    'provider' => $context->usage->provider,
                    'raw_usage' => $context->usage->rawUsage === null
                        ? null
                        : json_encode($context->usage->rawUsage, JSON_THROW_ON_ERROR),
                    'driver_meta_info' => $context->usage->driverMetaInfo === null
                        ? null
                        : json_encode($context->usage->driverMetaInfo, JSON_THROW_ON_ERROR),
                    'created_at' => date(self::DB_TIMESTAMP_FORMAT),
                ]);
            }
        });
    }
}

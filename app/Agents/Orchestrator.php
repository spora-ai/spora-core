<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
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
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Plugins\PluginLoader;
use Spora\Services\AgentServiceInterface;
use Spora\Services\LLMConfigService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\ScrubDataUrls;
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
    private const ISO8601_UTC = 'Y-m-d\TH:i:s\Z';

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
        $this->driverFactory         = $driverFactory;
        $this->errorClassifier       = new ErrorClassifier();
        $this->llmConfigResolver     = new LlmConfigResolver($config->llmConfigService);
        $this->toolDefinitionBuilder = new ToolDefinitionBuilder(
            $config->toolInstances,
            $config->toolConfigService,
            $config->pluginLoader,
            fn(array $llmSettings): string => $this->buildLlmConfigBlock($llmSettings),
        );
        $this->retryScheduler        = new RetryScheduler($this, $config->logger, $config->notificationService);
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
        );
        $this->toolCallExecutor      = new ToolCallExecutor($this);
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

    public function continue(int $taskId, string $newPrompt, ?int $additionalSteps = null, array $mediaIds = []): Task
    {
        $task = Task::findOrFail($taskId);

        if (!in_array($task->status, ['COMPLETED', 'FAILED'], true)) {
            throw new InvalidTaskTransitionException('Can only continue completed or failed tasks.');
        }

        $this->appendHistory($task->id, 'user', $newPrompt);

        if ($mediaIds !== []) {
            $this->appendAttachmentRow($task->id, $mediaIds);
        }

        $task->status = $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED';
        $task->step_count = 0;
        $task->user_prompt = Utf8Sanitizer::scrubString($newPrompt);

        if ($additionalSteps !== null) {
            $task->max_steps = $additionalSteps;
        }

        $task->save();

        if ($this->workerMode === WorkerMode::Sync) {
            $this->tick($task->id);
        }

        return $task->fresh();
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
            $task = $this->loadPendingTask($taskId);
            $state = $this->loadAgentState($task);

            [$approvedBatch, $rejectedBatch] = $this->splitDecisions($decisions, $state);
            $remainingState = $this->buildRemainingAgentState($state, $rejectedBatch);

            $this->markRejectionBatch($task, $rejectedBatch);

            $shouldTick = $this->applyResumeTransition($task, $approvedBatch, $remainingState);

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

    private function loadPendingTask(int $taskId): Task
    {
        $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();
        if ($task->status !== 'PENDING_APPROVAL') {
            throw new InvalidTaskTransitionException("Task {$taskId} is not awaiting approval.");
        }
        return $task;
    }

    private function loadAgentState(Task $task): AgentState
    {
        return $task->pending_state === null
            ? new AgentState(
                taskId: $task->id,
                agentId: $task->agent_id,
                pendingToolCalls: [],
                messageSnapshot: [],
                stepCount: $task->step_count,
                maxSteps: $task->max_steps,
                pausedAt: date(self::ISO8601_UTC),
            )
            : AgentState::fromJson($task->pending_state);
    }

    /**
     * @param list<array{provider_call_id: string, reason: string}> $rejectedBatch
     */
    private function buildRemainingAgentState(AgentState $state, array $rejectedBatch): AgentState
    {
        $rejectedIds = array_column($rejectedBatch, 'provider_call_id');
        $remainingCalls = array_values(array_filter(
            $state->pendingToolCalls,
            static fn($call): bool => !in_array($call->providerCallId, $rejectedIds, true),
        ));
        return new AgentState(
            taskId: $state->taskId,
            agentId: $state->agentId,
            pendingToolCalls: $remainingCalls,
            messageSnapshot: $state->messageSnapshot,
            stepCount: $state->stepCount,
            maxSteps: $state->maxSteps,
            pausedAt: date(self::ISO8601_UTC),
        );
    }

    /**
     * @param list<array{provider_call_id: string, reason: string}> $rejectedBatch
     */
    private function markRejectionBatch(Task $task, array $rejectedBatch): void
    {
        foreach ($rejectedBatch as $rejected) {
            $this->markSingleRejection($task, $rejected);
        }
    }

    /**
     * @param array{provider_call_id: string, reason: string} $rejected
     */
    private function markSingleRejection(Task $task, array $rejected): void
    {
        $model = ToolCallModel::where('task_id', $task->id)
            ->where('provider_call_id', $rejected['provider_call_id'])
            ->where('status', 'PENDING_APPROVAL')
            ->first();
        if ($model === null) {
            throw new InvalidArgumentException("provider_call_id '{$rejected['provider_call_id']}' is not pending approval.");
        }

        $model->update([
            'status'        => 'REJECTED',
            'rejected_at'   => date(self::DB_TIMESTAMP_FORMAT),
            'rejected_by'   => $task->user_id,
            'reject_reason' => $rejected['reason'],
        ]);

        $this->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub("Action rejected by user: {$rejected['reason']}"),
            context: new HistoryMessageContext(
                toolCallId: $model->provider_call_id,
                toolName: $model->tool_name,
            ),
        );
    }

    /**
     * @param list<array{provider_call_id: string, arguments: array<string, mixed>}> $approvedBatch
     */
    private function applyResumeTransition(Task $task, array $approvedBatch, AgentState $remainingState): bool
    {
        if ($approvedBatch !== []) {
            $task->pending_state = $remainingState->toJson();
            $task->save();
            return $this->workerMode === WorkerMode::Sync;
        }

        $hasPendingApprovals = ToolCallModel::where('task_id', $task->id)
            ->where('status', 'PENDING_APPROVAL')
            ->exists();
        if ($hasPendingApprovals) {
            $task->pending_state = $remainingState->toJson();
            $task->save();
            return false;
        }

        $task->pending_state = null;
        $task->status = $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED';
        $task->save();
        return $this->workerMode === WorkerMode::Sync;
    }

    /**
     * @param list<array<string, mixed>> $decisions
     * @return array{
     *     list<array{provider_call_id: string, arguments: array<string, mixed>}>,
     *     list<array{provider_call_id: string, reason: string}>
     * }
     */
    private function splitDecisions(array $decisions, AgentState $state): array
    {
        if ($decisions === []) {
            throw new InvalidArgumentException('decisions must be a non-empty array.');
        }

        $pendingIds = $this->indexPendingProviderCallIds($state);
        $approvedBatch = [];
        $rejectedBatch = [];

        /** @var list<mixed> $decisions */
        foreach ($decisions as $index => $decision) {
            $entry = $this->classifyDecision($decision, $index, $pendingIds);
            if ($entry['decision'] === 'approve') {
                $approvedBatch[] = $entry;
            } else {
                $rejectedBatch[] = $entry;
            }
        }

        return [$approvedBatch, $rejectedBatch];
    }

    /**
     * @return array<string, true>
     */
    private function indexPendingProviderCallIds(AgentState $state): array
    {
        $pendingIds = [];
        foreach ($state->pendingToolCalls as $pendingToolCall) {
            $pendingIds[$pendingToolCall->providerCallId] = true;
        }
        return $pendingIds;
    }

    /**
     * @param mixed $decision
     * @param array<string, true> $pendingIds
     * @return array{provider_call_id: string, decision: 'approve', arguments: array<string, mixed>}
     *        |array{provider_call_id: string, decision: 'reject', reason: string}
     */
    private function classifyDecision($decision, int $index, array $pendingIds): array
    {
        if (!is_array($decision)) {
            throw new InvalidArgumentException("Decision at index {$index} must be an array.");
        }
        $providerCallId = $this->validateProviderCallId($decision, $index, $pendingIds);
        $choice = $this->validateDecisionChoice($decision, $index);
        return $choice === 'approve'
            ? $this->buildApprovedEntry($decision, $providerCallId, $index)
            : $this->buildRejectedEntry($decision, $providerCallId, $index);
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, true> $pendingIds
     */
    private function validateProviderCallId(array $decision, int $index, array $pendingIds): string
    {
        $raw = $decision['provider_call_id'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            throw new InvalidArgumentException('provider_call_id is required in every decision.');
        }
        $providerCallId = trim($raw);
        if (!isset($pendingIds[$providerCallId])) {
            throw new InvalidArgumentException("Decision at index {$index} has provider_call_id '{$providerCallId}' which is not pending approval.");
        }
        return $providerCallId;
    }

    /**
     * @param array<string, mixed> $decision
     */
    private function validateDecisionChoice(array $decision, int $index): string
    {
        $choice = $decision['decision'] ?? null;
        if (!is_string($choice) || !in_array($choice, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException("Decision at index {$index} must have decision either 'approve' or 'reject'.");
        }
        return $choice;
    }

    /**
     * @param array<string, mixed> $decision
     * @return array{provider_call_id: string, decision: 'approve', arguments: array<string, mixed>}
     */
    private function buildApprovedEntry(array $decision, string $providerCallId, int $index): array
    {
        $arguments = $decision['arguments'] ?? null;
        if (!is_array($arguments)) {
            throw new InvalidArgumentException("Decision at index {$index} has decision 'approve' but arguments is not an array.");
        }
        return [
            'provider_call_id' => $providerCallId,
            'decision'         => 'approve',
            'arguments'        => $arguments,
        ];
    }

    /**
     * @param array<string, mixed> $decision
     * @return array{provider_call_id: string, decision: 'reject', reason: string}
     */
    private function buildRejectedEntry(array $decision, string $providerCallId, int $index): array
    {
        $reason = $decision['reason'] ?? 'User rejected';
        if (!is_string($reason)) {
            throw new InvalidArgumentException("Decision at index {$index} has decision 'reject' but reason is not a string.");
        }
        $reason = trim($reason);
        return [
            'provider_call_id' => $providerCallId,
            'decision'         => 'reject',
            'reason'           => $reason === '' ? 'User rejected' : $reason,
        ];
    }

    public function reject(int $taskId, string $reason): void
    {
        $task = null;
        $state = null;

        Capsule::connection()->transaction(function () use ($taskId, &$task, &$state) {
            $task = $this->loadPendingTask($taskId);
            $state = $this->loadAgentState($task);
            $task->pending_state = null;
            $task->save();
        });

        try {
            if (!$task instanceof Task || !$state instanceof AgentState) {
                throw new TaskStateMissingException('Failed to resolve task or state during reject.');
            }
            $this->recordBulkRejection($task, $reason);
            $this->transitionTaskAfterRejection($taskId);
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

    private function recordBulkRejection(Task $task, string $reason): void
    {
        $pendingModels = ToolCallModel::where('task_id', $task->id)
            ->where('status', 'PENDING_APPROVAL')
            ->get();
        ToolCallModel::where('task_id', $task->id)
            ->where('status', 'PENDING_APPROVAL')
            ->update(['status' => 'REJECTED']);
        foreach ($pendingModels as $model) {
            $this->appendRejectionHistory($task, $model, $reason);
        }
    }

    private function appendRejectionHistory(Task $task, ToolCallModel $model, string $reason): void
    {
        $this->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub("Action rejected by user: {$reason}"),
            context: new HistoryMessageContext(
                toolCallId: $model->provider_call_id,
                toolName: $model->tool_name,
            ),
        );
    }

    private function transitionTaskAfterRejection(int $taskId): void
    {
        $taskStatus = $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED';
        Task::where('id', $taskId)->update(['status' => $taskStatus]);
        if ($this->workerMode === WorkerMode::Sync) {
            // Tick is called after the transaction commits so the LLM round-trip
            // does not hold the lockForUpdate open for its full duration.
            $this->tick($taskId);
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

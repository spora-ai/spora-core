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
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Plugins\PluginLoader;
use Spora\Services\LLMConfigService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\ScrubDataUrls;
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
            'user_prompt'   => $userPrompt,
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
        $task->user_prompt = $newPrompt;

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
                throw new \InvalidArgumentException("Media asset {$mid} is not owned by the current user.");
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
            new \Spora\Agents\ValueObjects\HistoryMessageContext(attachments: $refs),
        );
    }

    public function tick(int $taskId): void
    {
        $this->tickPhaseRunner->runTick($taskId);
    }

    /**
     * Execute the batch of tool calls that were paused for human approval.
     *
     * {@inheritDoc}
     */
    public function resume(int $taskId, array $approvedBatch): void
    {
        $this->approvedBatchExecutor->execute($taskId, $approvedBatch);
    }

    public function reject(int $taskId, string $reason): void
    {
        $task = null;
        $state = null;

        Capsule::connection()->transaction(function () use ($taskId, &$task, &$state) {
            /** @var Task $task */
            $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();

            if ($task->status !== 'PENDING_APPROVAL') {
                throw new InvalidTaskTransitionException("Task {$taskId} is not awaiting approval.");
            }
            if ($task->pending_state === null) {
                $state = new AgentState(taskId: $task->id, agentId: $task->agent_id, pendingToolCalls: [], messageSnapshot: [], stepCount: $task->step_count, maxSteps: $task->max_steps, pausedAt: date(self::ISO8601_UTC));
            } else {
                $state = AgentState::fromJson($task->pending_state);
            }

            $task->pending_state = null;
            $task->save();
        });

        try {
            if (!$task instanceof Task || !$state instanceof AgentState) {
                throw new TaskStateMissingException('Failed to resolve task or state during reject.');
            }

            $pendingModels = ToolCallModel::where('task_id', $taskId)
                ->where('status', 'PENDING_APPROVAL')
                ->get();

            ToolCallModel::where('task_id', $taskId)
                ->where('status', 'PENDING_APPROVAL')
                ->update(['status' => 'REJECTED']);

            foreach ($pendingModels as $model) {
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

            $taskStatus = $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED';
            Task::where('id', $taskId)->update(['status' => $taskStatus]);

            if ($this->workerMode === WorkerMode::Sync) {
                // Tick is called after the transaction commits so the LLM round-trip
                // does not hold the lockForUpdate open for its full duration.
                $this->tick($taskId);
            }

        } catch (Throwable $e) {
            Task::where('id', $taskId)->update([
                'status'         => 'FAILED',
                'error_code'     => 'REJECT_FAILED',
                'error_message'  => 'Task reject failed: ' . $e->getMessage(),
                'failure_reason' => $e->getMessage(),
            ]);
            throw $e;
        }
    }


    public function safeExecute(
        ToolInterface $toolInstance,
        array $arguments,
        int $agentId,
        int $taskId,
        ?int $userId = null,
    ): ToolResult {
        $ref      = new ReflectionClass($toolInstance);
        $attrs    = $ref->getAttributes(Tool::class);
        $toolName = $attrs !== [] ? $attrs[0]->newInstance()->name : get_class($toolInstance);

        // Arguments may contain PII — never log them.
        $this->logger?->debug('Tool dispatch', [
            'tool'      => $toolName,
            'agent_id'  => $agentId,
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
            } catch (\Throwable) {
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
            'task_id'           => $taskId,
            'role'              => $role,
            'content'           => $content,
            'tool_call_id'      => $context->toolCallId,
            'tool_name'         => $context->toolName,
            'tool_call_payload' => $context->toolCallPayload,
            'input_tokens'      => $context->inputTokens,
            'output_tokens'     => $context->outputTokens,
        ];

        // Write reasoning unconditionally as the column is now part of the base schema
        if ($context->reasoning !== null) {
            $row['reasoning'] = $context->reasoning;
        }

        if ($context->attachments !== null) {
            $row['attachments'] = json_encode($context->attachments, JSON_THROW_ON_ERROR);
        }

        Capsule::connection()->transaction(function () use ($taskId, $row) {
            $nextSeq = TaskHistory::where('task_id', $taskId)->lockForUpdate()->max('sequence') ?? -1;
            $row['sequence'] = $nextSeq + 1;
            TaskHistory::create($row);
        });
    }
}

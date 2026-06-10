<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Spora\Agents\Exceptions\InvalidTaskTransitionException;
use Spora\Agents\Exceptions\LlmConfigurationMissingException;
use Spora\Agents\Exceptions\TaskStateMissingException;
use Spora\Agents\Exceptions\ToolContractException;
use Spora\Agents\Exceptions\ToolNotRegisteredException;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Plugins\PluginLoader;
use Spora\Services\LLMConfigService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\ToolCallSerializer;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Schema\OperationSchemaFilter;
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

    /**
     * @param list<object> $toolInstances
     */
    public function __construct(
        private readonly DriverFactory              $driverFactory,
        private readonly LLMConfigService|null    $llmConfigService = null,
        private readonly array                      $toolInstances = [],
        private readonly ?LoggerInterface           $logger         = null,
        private readonly WorkerMode                 $workerMode     = WorkerMode::Sync,
        private readonly ?NotificationService      $notificationService = null,
        private readonly ?PluginLoader              $pluginLoader   = null,
        private readonly ?MercurePublisherInterface $mercure       = null,
        private readonly ?ToolConfigService        $toolConfigService = null,
        private readonly ?ToolCallSerializer       $toolCallSerializer = null,
        private readonly ?ErrorClassifier          $errorClassifier = null,
        private readonly ?ToolDefinitionBuilder   $toolDefinitionBuilder = null,
        private readonly ?LlmConfigResolver       $llmConfigResolver = null,
        private readonly ?RetryScheduler          $retryScheduler = null,
        private readonly ?ContextWindowRecovery  $contextWindowRecovery = null,
    ) {}

    // Public API

    public function start(int $agentId, string $userPrompt, int $maxSteps = 10, ?int $parentTaskId = null, ?int $runId = null): Task
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

        if ($this->workerMode === WorkerMode::Sync) {
            $this->tick($task->id);
        }

        return $task->fresh();
    }

    public function continue(int $taskId, string $newPrompt, ?int $additionalSteps = null): Task
    {
        $task = Task::findOrFail($taskId);

        if (!in_array($task->status, ['COMPLETED', 'FAILED'], true)) {
            throw new InvalidTaskTransitionException('Can only continue completed or failed tasks.');
        }

        $this->appendHistory($task->id, 'user', $newPrompt);

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

    public function tick(int $taskId): void
    {
        $task = $this->lockRunningTaskForTick($taskId);
        if ($task === null) {
            return;
        }

        try {
            $context = $this->prepareTickContext($task);
        } catch (RuntimeException $e) {
            $this->errorClassifier()->markTaskNoLlmConfiguration($taskId, $e);
            throw $e;
        }

        Task::where('id', $taskId)->increment('step_count');

        try {
            $response = $this->dispatchLlmRequest($context);
            $this->handleTickLlmResponse($context, $response);
        } catch (Throwable $e) {
            $this->handleTickFailure($taskId, $context, $e);
        }
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
                $task->failure_reason = 'Max steps reached.';
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

        $llmConfig = $this->llmConfigResolver()->resolveLlmConfig($agent);

        $request = new LLMRequest(
            systemPrompt: $this->resolveSystemPrompt($agent),
            messages: $this->buildMessages($task->id),
            tools: $this->toolDefinitionBuilder()->buildToolDefinitions($enabledClasses, $agent->id, $agent->user_id),
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
     *   agent: Agent,
     *   enabledClasses: list<string>
     * } $context
     */
    private function handleTickLlmResponse(array $context, LLMResponse $response): void
    {
        $task           = $context['task'];
        $agent          = $context['agent'];
        $enabledClasses = $context['enabledClasses'];

        if ($response->hasToolCalls()) {
            $this->recordAssistantToolCallBatch($task, $response);
            $this->handleToolCalls($task, $agent, $response->toolCalls, $enabledClasses);
            return;
        }

        $this->completeTaskWithResponse($task, $response);
    }

    private function recordAssistantToolCallBatch(Task $task, LLMResponse $response): void
    {
        $this->appendHistory(
            taskId: $task->id,
            role: 'assistant',
            content: null,
            context: new HistoryMessageContext(
                toolCallPayload: json_encode(
                    array_map(static fn(DriverToolCall $tc) => [
                        'id'       => $tc->providerCallId,
                        'type'     => 'function',
                        'function' => [
                            'name'      => $tc->toolName,
                            // Normalize empty array [] to {} for strict providers
                            'arguments' => empty($tc->arguments) ? '{}' : json_encode($tc->arguments, JSON_THROW_ON_ERROR),
                        ],
                    ], $response->toolCalls),
                    JSON_THROW_ON_ERROR,
                ),
                inputTokens: $response->inputTokens,
                outputTokens: $response->outputTokens,
                reasoning: $response->reasoning,
            ),
        );
    }

    private function completeTaskWithResponse(Task $task, LLMResponse $response): void
    {
        $this->appendHistory(
            taskId: $task->id,
            role: 'assistant',
            content: $response->content,
            context: new HistoryMessageContext(
                inputTokens: $response->inputTokens,
                outputTokens: $response->outputTokens,
                reasoning: $response->reasoning,
            ),
        );

        $task->status         = 'COMPLETED';
        $task->final_response = $response->content;
        $task->save();

        if (!isset($task->data['run_id'])) {
            $this->notificationService?->notifyTaskCompleted($task);
        }
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

        if ($this->errorClassifier()->isContextWindowError($e)) {
            $this->contextWindowRecovery()->tryCompactionAndRetry($context['task'], $context['agent'], $e);
            return;
        }

        $errorCode = $this->errorClassifier()->classifyError($e);
        $friendlyMsg = $this->errorClassifier()->friendlyMessageForError($e, $errorCode);

        try {
            $updated = Task::where('id', $taskId)
                ->where('status', 'RUNNING')
                ->update([
                    'status'         => 'FAILED',
                    'failure_reason' => $e->getMessage(),
                    'error_code'     => $errorCode,
                    'error_message'  => $friendlyMsg,
                ]);

            if ($updated > 0) {
                $failedTask = Task::where('id', $taskId)->first();
                if ($failedTask !== null) {
                    $this->notifyFailedAndScheduleRetry($failedTask, $errorCode);
                }
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
            $this->retryScheduler()->scheduleAutoRetry($failedTask, $errorCode);
        } catch (Throwable $e) {
            $this->logger?->warning('Auto-retry scheduling failed', [
                'task_id'   => $failedTask->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private ?ContextWindowRecovery $contextWindowRecoveryInstance = null;

    private function contextWindowRecovery(): ContextWindowRecovery
    {
        return $this->contextWindowRecoveryInstance ??= new ContextWindowRecovery(
            $this,
            $this->logger,
            $this->notificationService,
        );
    }

    /**
     * Exposed for {@see ContextWindowRecovery} so it can build the LLM
     * driver for history-compaction summaries. Not part of the public API.
     */
    public function getDriverFactory(): DriverFactory
    {
        return $this->driverFactory;
    }

    /**
     * Execute the batch of tool calls that were paused for human approval.
     *
     * {@inheritDoc}
     */
    public function resume(int $taskId, array $approvedBatch): void
    {
        $this->approvedBatchExecutor()->execute($taskId, $approvedBatch);
    }

    private ?ApprovedBatchExecutor $approvedBatchExecutorInstance = null;

    public function approvedBatchExecutor(): ApprovedBatchExecutor
    {
        return $this->approvedBatchExecutorInstance ??= new ApprovedBatchExecutor(
            $this,
            $this->workerMode,
            $this->logger,
        );
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
                    content: "Action rejected by user: {$reason}",
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


    private function handleToolCalls(Task $task, Agent $agent, array $toolCalls, array $enabledClasses): void
    {
        /** @var list<DriverToolCall> $pendingApproval */
        $pendingApproval = [];

        foreach ($toolCalls as $toolCall) {
            try {
                $disposition = $this->toolCallExecutor()->executeOrQueue($toolCall, $agent, $task, $enabledClasses);

                if ($disposition === ToolCallDisposition::AwaitingApproval) {
                    $pendingApproval[] = $toolCall;
                }
            } catch (Throwable $e) {
                $this->appendHistory(
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
            $this->publishIntermediateState($task);
            $this->tick($task->id);
        } else {
            $state = new AgentState(
                taskId: $task->id,
                agentId: $agent->id,
                pendingToolCalls: $pendingApproval,
                messageSnapshot: $this->buildMessages($task->id),
                stepCount: $task->step_count,
                maxSteps: $task->max_steps,
                pausedAt: date(self::ISO8601_UTC),
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

    private ?ToolCallExecutor $toolCallExecutorInstance = null;

    private function toolCallExecutor(): ToolCallExecutor
    {
        return $this->toolCallExecutorInstance ??= new ToolCallExecutor($this);
    }

    private function publishIntermediateState(Task $task): void
    {
        if ($this->mercure === null) {
            return;
        }

        $serializer = $this->toolCallSerializer ?? new ToolCallSerializer($this->toolInstances);

        $taskData = [
            'id'         => $task->id,
            'status'     => $task->status,
            'step_count' => $task->step_count,
            'tool_calls' => $task->toolCalls->map(fn(ToolCallModel $tc) => $serializer->toArray($tc))->all(),
            'history' => $task->taskHistory()->orderBy('sequence')->get()->map(fn(TaskHistory $h) => [
                'sequence'     => $h->sequence,
                'role'         => $h->role,
                'content'      => $h->content,
                'reasoning'    => $h->reasoning,
                'tool_call_id' => $h->tool_call_id,
                'tool_name'    => $h->tool_name,
            ])->all(),
        ];

        $this->mercure->publish($task->id, $task->user_id, $taskData);
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

    private function buildMessages(int $taskId): array
    {
        return (new MessageHistoryBuilder())->build($taskId);
    }

    /**
     * Thin wrapper kept on the orchestrator so the existing test suite
     * can call it via reflection. Delegates to {@see ToolDefinitionBuilder}.
     *
     * @param  list<string>  $enabledClasses
     * @return list<array<string, mixed>>
     */
    private function buildToolDefinitions(array $enabledClasses, int $agentId, ?int $userId = null): array
    {
        return $this->toolDefinitionBuilder()->buildToolDefinitions($enabledClasses, $agentId, $userId);
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

        Capsule::connection()->transaction(function () use ($taskId, $row) {
            $nextSeq = TaskHistory::where('task_id', $taskId)->lockForUpdate()->max('sequence') ?? -1;
            $row['sequence'] = $nextSeq + 1;
            TaskHistory::create($row);
        });
    }

    private ?RetryScheduler $retrySchedulerInstance = null;

    public function retryScheduler(): RetryScheduler
    {
        return $this->retrySchedulerInstance ??= new RetryScheduler(
            $this,
            $this->logger,
            $this->notificationService,
        );
    }

    private ?ErrorClassifier $errorClassifierInstance = null;

    public function errorClassifier(): ErrorClassifier
    {
        return $this->errorClassifierInstance ??= new ErrorClassifier($this->logger);
    }

    private ?ToolDefinitionBuilder $toolDefinitionBuilderInstance = null;

    public function toolDefinitionBuilder(): ToolDefinitionBuilder
    {
        return $this->toolDefinitionBuilderInstance ??= new ToolDefinitionBuilder(
            $this->toolInstances,
            $this->toolConfigService,
            $this->pluginLoader,
            fn(array $llmSettings): string => $this->buildLlmConfigBlock($llmSettings),
        );
    }

    private ?LlmConfigResolver $llmConfigResolverInstance = null;

    public function llmConfigResolver(): LlmConfigResolver
    {
        return $this->llmConfigResolverInstance ??= new LlmConfigResolver($this->llmConfigService);
    }
}

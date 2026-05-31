<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Plugins\PluginLoader;
use Spora\Services\ContextWindowErrorParser;
use Spora\Services\LLMConfigService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Throwable;

final class Orchestrator implements OrchestratorInterface
{
    /** Error codes that qualify for auto-retry. */
    private const RETRYABLE_ERROR_CODES = [
        'RATE_LIMIT',
        'SERVER_OVERLOADED',
        'SERVER_ERROR',
        'GATEWAY_ERROR',
        'AUTH_ERROR',
        'LLM_TIMEOUT',
        'ORPHANED',
    ];

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
            throw new RuntimeException('Can only continue completed or failed tasks.');
        }

        $this->appendHistory($task->id, 'user', $newPrompt);

        $task->status = $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED';
        $task->step_count = 0;

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
        $context = null;

        Capsule::connection()->transaction(function () use ($taskId, &$context): void {
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

            $context = ['task' => $task];
        });

        if ($context === null) {
            return;
        }

        try {
            $task = $context['task'];
            $agent          = Agent::findOrFail($task->agent_id);
            $enabledClasses = AgentTool::where('agent_id', $agent->id)->pluck('tool_class')->toArray();

            $systemPrompt = ($agent->system_prompt !== null && $agent->system_prompt !== '')
                ? $agent->system_prompt
                : 'You are a helpful AI assistant.';

            $llmConfig = $this->resolveLlmConfig($agent);
            $contextWindow = $llmConfig['context_window'];
            $maxTokensOutput = $llmConfig['max_tokens_output'];
            $temperature = $llmConfig['temperature'];

            $context = [
                'task'           => $task,
                'agent'          => $agent,
                'enabledClasses' => $enabledClasses,
                'contextWindow'  => $contextWindow,
                'maxTokensOutput' => $maxTokensOutput,
                'request'        => new LLMRequest(
                    systemPrompt: $systemPrompt,
                    messages: $this->buildMessages($taskId),
                    tools: $this->buildToolDefinitions($enabledClasses, $agent->id, $agent->user_id),
                    contextWindow: $contextWindow,
                    maxTokens: $maxTokensOutput,
                    temperature: $temperature,
                ),
            ];
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'No LLM configuration')) {
                Task::where('id', $taskId)->update([
                    'status'         => 'FAILED',
                    'failure_reason' => $e->getMessage(),
                    'error_code'     => 'NO_LLM_CONFIGURATION',
                    'error_message'  => 'No LLM configuration set. Please configure an LLM driver or set a global default.',
                ]);
            }
            throw $e;
        }

        Task::where('id', $taskId)->increment('step_count');

        try {
            $response = $this->driverFactory->makeFromAgent($context['agent'])->complete($context['request']);

            $task           = $context['task'];
            $agent          = $context['agent'];
            $enabledClasses = $context['enabledClasses'];

            if ($response->hasToolCalls()) {
                $this->appendHistory(
                    taskId: $task->id,
                    role: 'assistant',
                    content: null,
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
                );

                $this->handleToolCalls($task, $agent, $response->toolCalls, $enabledClasses);
            } else {
                $this->appendHistory(
                    taskId: $task->id,
                    role: 'assistant',
                    content: $response->content,
                    inputTokens: $response->inputTokens,
                    outputTokens: $response->outputTokens,
                    reasoning: $response->reasoning,
                );

                $task->status         = 'COMPLETED';
                $task->final_response = $response->content;
                $task->save();

                if (! isset($task->data['run_id'])) {
                    $this->notificationService?->notifyTaskCompleted($task);
                }
            }
        } catch (Throwable $e) {
            $this->logger?->error('tick() failed', [
                'task_id'         => $taskId,
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]);

            $task = $context['task'];
            $agent = $context['agent'];

            if ($this->isContextWindowError($e)) {
                $this->tryCompactionAndRetry($task, $agent, $e);
                return;
            }

            $errorCode = $this->classifyError($e);
            $friendlyMsg = $this->friendlyMessageForError($e, $errorCode);

            try {
                $updated = Task::where('id', $taskId)
                    ->where('status', 'RUNNING')
                    ->update([
                        'status'         => 'FAILED',
                        'failure_reason' => $e->getMessage(),
                        'error_code'     => $errorCode,
                        'error_message' => $friendlyMsg,
                    ]);

                if ($updated > 0) {
                    $failedTask = Task::where('id', $taskId)->first();
                    if ($failedTask !== null) {
                        try {
                            $this->notificationService?->notifyTaskFailed($failedTask);
                        } catch (Throwable $e) {
                            $this->logger?->warning('Notification failed', ['task_id' => $failedTask->id, 'exception' => $e->getMessage()]);
                        }

                        try {
                            $this->scheduleAutoRetry($failedTask, $errorCode);
                        } catch (Throwable $e) {
                            $this->logger?->warning('Auto-retry scheduling failed', ['task_id' => $failedTask->id, 'exception' => $e->getMessage()]);
                        }
                    }
                }
            } catch (Throwable) {
                // Ignore failure — DB itself may be unavailable.
            }

            throw $e;
        }
    }

    private function isContextWindowError(Throwable $e): bool
    {
        if (!$e instanceof LLMProviderException) {
            return false;
        }

        $rawBody = $e->getMessage();
        // Extract JSON body from "Provider API error N: {...}" format
        if (preg_match('/\{.*\}/s', $rawBody, $matches)) {
            $parser = new ContextWindowErrorParser();
            return $parser->isContextWindowError($matches[0]);
        }

        return false;
    }

    private function tryCompactionAndRetry(?Task $task, ?Agent $agent, Throwable $originalError): void
    {
        if ($task === null || $agent === null) {
            throw $originalError;
        }

        $taskId = $task->id;

        $historyCount = TaskHistory::where('task_id', $taskId)->count();
        if ($historyCount <= 1) {
            $actualLimit = $this->extractActualContextWindow($originalError);
            $msg = $actualLimit !== null
                ? "Context window too small ({$actualLimit} tokens). The model cannot process this request even without any conversation history. Try a model with a larger context window (e.g., 128K+ tokens) or reduce the system prompt length."
                : "Context window too small for the current prompt. The model cannot process this request even without any conversation history. Try a model with a larger context window (e.g., 128K+ tokens) or reduce the system prompt length.";

            Task::where('id', $taskId)->where('status', 'RUNNING')->update([
                'status'         => 'FAILED',
                'failure_reason' => $originalError->getMessage(),
                'error_code'     => 'CONTEXT_WINDOW_FIRST_TURN',
                'error_message' => $msg,
            ]);

            $this->notificationService?->notifyTaskFailed(Task::find($taskId));
            throw $originalError;
        }

        $llmConfig = $this->resolveLlmConfig($agent);
        $maxTokensOutput = $llmConfig['max_tokens_output'];
        $temperature = $llmConfig['temperature'];

        $this->logger?->info('Context window error, compacting history and retrying', ['task_id' => $taskId]);

        try {
            $this->compactHistory($taskId, $maxTokensOutput, $temperature, $agent);
        } catch (Throwable $e) {
            $this->logger?->warning('Compaction failed', ['task_id' => $taskId, 'exception' => $e->getMessage()]);
            throw $originalError;
        }

        try {
            $this->tick($taskId);
        } catch (Throwable) {
            throw $originalError;
        }
    }

    private function compactHistory(int $taskId, int $maxTokensOutput, float $temperature, Agent $agent): void
    {
        $allRows = TaskHistory::where('task_id', $taskId)
            ->orderBy('sequence')
            ->get();

        $keepCount = 5;
        if ($allRows->count() <= $keepCount + 1) {
            return;
        }

        $toSummarizeRows = $allRows->take($allRows->count() - $keepCount);

        $firstRow = $toSummarizeRows->first();
        $lastRow = $toSummarizeRows->last();
        $firstSeq = $firstRow !== null ? $firstRow->sequence : 0;
        $lastSeq = $lastRow !== null ? $lastRow->sequence : 0;

        $summaryMessages = [];
        foreach ($toSummarizeRows as $row) {
            $content = $row->content ?? '';
            if ($row->role === 'tool') {
                $content = "[{$row->tool_name}]: " . $content;
            }
            if ($content !== '') {
                $summaryMessages[] = ['role' => $row->role, 'content' => $content];
            }
        }

        if ($summaryMessages === []) {
            TaskHistory::where('task_id', $taskId)
                ->where('sequence', '>=', $firstSeq)
                ->where('sequence', '<=', $lastSeq)
                ->delete();
            return;
        }

        $systemPrompt = ($agent->system_prompt !== null && $agent->system_prompt !== '')
            ? $agent->system_prompt
            : 'You are a helpful AI assistant.';

        $summaryInstruction = 'Summarize the conversation below concisely, preserving key facts, decisions, and any pending tasks. Output only the summary.';

        $summaryRequest = new LLMRequest(
            systemPrompt: $systemPrompt,
            messages: array_merge($summaryMessages, [['role' => 'user', 'content' => $summaryInstruction]]),
            tools: [],
            maxTokens: min($maxTokensOutput, 1024),
            temperature: $temperature,
        );

        $driver = $this->driverFactory->makeFromAgent($agent);
        $response = $driver->complete($summaryRequest);

        $summaryText = $response->content ?? 'Conversation summarized.';

        Capsule::connection()->transaction(function () use ($taskId, $firstSeq, $lastSeq, $summaryText) {
            TaskHistory::where('task_id', $taskId)
                ->where('sequence', '>=', $firstSeq)
                ->where('sequence', '<=', $lastSeq)
                ->delete();

            TaskHistory::create([
                'task_id' => $taskId,
                'sequence' => $firstSeq,
                'role' => 'summary',
                'content' => $summaryText,
                'summarized_sequence_range' => "{$firstSeq}-{$lastSeq}",
            ]);

            $remaining = TaskHistory::where('task_id', $taskId)
                ->where('sequence', '>', $lastSeq)
                ->orderBy('sequence')
                ->get();

            $nextSeq = $lastSeq + 1;
            foreach ($remaining as $row) {
                $row->sequence = $nextSeq;
                $row->save();
                $nextSeq++;
            }
        });
    }

    private function extractActualContextWindow(Throwable $e): ?int
    {
        if (!$e instanceof LLMProviderException) {
            return null;
        }

        if (preg_match('/\{.*\}/s', $e->getMessage(), $matches)) {
            $parser = new ContextWindowErrorParser();
            $parsed = $parser->parse($matches[0]);
            return $parsed['actual_context_window'];
        }

        return $e->getActualContextWindow();
    }

    /**
     * Build friendly message for an error, with extra context for context window errors.
     */
    private function friendlyMessageForError(Throwable $e, string $errorCode): string
    {
        $base = $this->friendlyMessages()[$errorCode] ?? $this->friendlyMessages()['UNKNOWN'];

        if ($this->isContextWindowError($e)) {
            $actualLimit = $this->extractActualContextWindow($e);
            if ($actualLimit !== null) {
                return "Context window exceeded ({$actualLimit} tokens). Try reducing history depth, choosing a model with larger context, or adjusting max_tokens_output.";
            }
        }

        return $base;
    }

    /**
     * Execute the batch of tool calls that were paused for human approval.
     *
     * {@inheritDoc}
     */
    public function resume(int $taskId, array $approvedBatch): void
    {
        $task = null;
        $state = null;

        Capsule::connection()->transaction(function () use ($taskId, &$task, &$state) {
            /** @var Task $task */
            $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();

            if ($task->status !== 'PENDING_APPROVAL') {
                throw new InvalidArgumentException("Task {$taskId} is not awaiting approval.");
            }
            if ($task->pending_state === null) {
                $state = new AgentState(taskId: $task->id, agentId: $task->agent_id, pendingToolCalls: [], messageSnapshot: [], stepCount: $task->step_count, maxSteps: $task->max_steps, pausedAt: date('Y-m-d\\TH:i:s\\Z'));

            } else {
                $state = AgentState::fromJson($task->pending_state);
            }

            $task->pending_state = null;
            $task->save();
        });

        try {
            if (!$task instanceof Task || !$state instanceof AgentState) {
                throw new RuntimeException('Failed to resolve task or state during resume.');
            }

            $this->logger?->info('Task resumed after approval', [
                'task_id' => $task->id,
                'approved_count' => count($approvedBatch),
            ]);

            $approvedMap = [];
            foreach ($approvedBatch as $item) {
                $approvedMap[$item['provider_call_id']] = $item['arguments'];
            }

            foreach ($state->pendingToolCalls as $pendingToolCall) {
                $approvedArgs = $approvedMap[$pendingToolCall->providerCallId] ?? $pendingToolCall->arguments;

                $toolInstance = $this->resolveToolByName($pendingToolCall->toolName);

                try {
                    SchemaValidator::validate($approvedArgs, $toolInstance->getParametersSchema());
                } catch (Throwable $e) {
                    $result = new ToolResult(false, 'Validation Error: ' . $e->getMessage());
                    $this->appendHistory(
                        taskId: $task->id,
                        role: 'tool',
                        content: $result->content,
                        toolCallId: $pendingToolCall->providerCallId,
                        toolName: $pendingToolCall->toolName,
                    );
                    ToolCallModel::where('task_id', $taskId)
                        ->where('provider_call_id', $pendingToolCall->providerCallId)
                        ->update([
                            'status'         => 'APPROVED',
                            'result_content' => $result->content,
                            'executed_at'    => date('Y-m-d H:i:s'),
                        ]);
                    continue;
                }

                $result = $this->safeExecute($toolInstance, $approvedArgs, $state->agentId, $taskId, $task->user_id);

                ToolCallModel::where('task_id', $taskId)
                    ->where('provider_call_id', $pendingToolCall->providerCallId)
                    ->update([
                        'status'             => 'APPROVED',
                        'approved_arguments' => json_encode($approvedArgs, JSON_THROW_ON_ERROR),
                        'result_content'     => $result->content,
                        'result_data'        => $result->data ? json_encode($result->data, JSON_THROW_ON_ERROR) : null,
                        'executed_at'        => date('Y-m-d H:i:s'),
                    ]);

                // Append the tool result into history so the LLM sees it on the next tick.
                $this->appendHistory(
                    taskId: $task->id,
                    role: 'tool',
                    content: $result->content,
                    toolCallId: $pendingToolCall->providerCallId,
                    toolName: $pendingToolCall->toolName,
                );
            }

            // Clean up any stranded PENDING_APPROVAL records from concurrency bugs.
            $danglingTools = ToolCallModel::where('task_id', $taskId)
                ->where('status', 'PENDING_APPROVAL')
                ->get();

            foreach ($danglingTools as $danglingTool) {
                $this->appendHistory(
                    taskId: $task->id,
                    role: 'tool',
                    content: 'Action discarded (state mismatch/timeout)',
                    toolCallId: $danglingTool->provider_call_id,
                    toolName: $danglingTool->tool_name,
                );
            }

            ToolCallModel::where('task_id', $taskId)
                ->where('status', 'PENDING_APPROVAL')
                ->update(['status' => 'REJECTED']);

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
                'error_code'     => 'RESUME_FAILED',
                'error_message'  => 'Task resume failed: ' . $e->getMessage(),
                'failure_reason' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reject(int $taskId, string $reason): void
    {
        $task = null;
        $state = null;

        Capsule::connection()->transaction(function () use ($taskId, &$task, &$state) {
            /** @var Task $task */
            $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();

            if ($task->status !== 'PENDING_APPROVAL') {
                throw new InvalidArgumentException("Task {$taskId} is not awaiting approval.");
            }
            if ($task->pending_state === null) {
                $state = new AgentState(taskId: $task->id, agentId: $task->agent_id, pendingToolCalls: [], messageSnapshot: [], stepCount: $task->step_count, maxSteps: $task->max_steps, pausedAt: date('Y-m-d\TH:i:s\Z'));
            } else {
                $state = AgentState::fromJson($task->pending_state);
            }

            $task->pending_state = null;
            $task->save();
        });

        try {
            if (!$task instanceof Task || !$state instanceof AgentState) {
                throw new RuntimeException('Failed to resolve task or state during reject.');
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
                    toolCallId: $model->provider_call_id,
                    toolName: $model->tool_name,
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
                $toolInstance = $this->resolveToolByName($toolCall->toolName);
                $toolClass    = get_class($toolInstance);

                if (!in_array($toolClass, $enabledClasses, true)) {
                    throw new RuntimeException("The LLM attempted to call tool '{$toolCall->toolName}' which is not enabled for this agent.");
                }

                $operationName        = 'default';
                $operationDescription = null;
                $usesOperations       = in_array(HasOperations::class, class_uses_recursive($toolClass), true);

                if ($usesOperations) {
                    $operationName        = $this->callTraitMethod($toolInstance, 'getOperationName', [$toolCall->arguments]);
                    $operationDescription = $this->callTraitMethod($toolInstance, 'getOperationDescription', [$operationName]);

                    if (!$this->isOperationEnabled($toolInstance, $operationName, $agent->id)) {
                        ToolCallModel::create([
                            'task_id'               => $task->id,
                            'agent_id'              => $agent->id,
                            'provider_call_id'      => $toolCall->providerCallId,
                            'tool_name'             => $toolCall->toolName,
                            'tool_class'            => $toolClass,
                            'tool_type'             => 'operation',
                            'operation'             => $operationName,
                            'operation_description' => $operationDescription,
                            'status'                => 'DISABLED',
                            'proposed_arguments'    => json_encode($toolCall->arguments, JSON_THROW_ON_ERROR),
                            'human_description'     => $operationDescription,
                        ]);
                        $this->appendHistory(
                            taskId: $task->id,
                            role: 'tool',
                            content: "Operation '{$operationName}' is disabled for this agent.",
                            toolCallId: $toolCall->providerCallId,
                            toolName: $toolCall->toolName,
                        );
                        continue;
                    }
                }

                $requiresApproval = $this->resolveRequiresApproval($toolInstance, $toolClass, $agent->id, $toolCall->arguments);

                $toolCallRecord = ToolCallModel::create([
                    'task_id'               => $task->id,
                    'agent_id'              => $agent->id,
                    'provider_call_id'      => $toolCall->providerCallId,
                    'tool_name'             => $toolCall->toolName,
                    'tool_class'            => $toolClass,
                    'tool_type'             => $requiresApproval ? 'output' : 'input',
                    'operation'             => $operationName,
                    'operation_description' => $operationDescription,
                    'status'                => 'PENDING_APPROVAL',
                    'proposed_arguments'    => json_encode($toolCall->arguments, JSON_THROW_ON_ERROR),
                    'human_description'     => $toolInstance->describeAction($toolCall->arguments),
                ]);

                try {
                    SchemaValidator::validate($toolCall->arguments, $toolInstance->getParametersSchema());
                } catch (Throwable $e) {
                    $result = new ToolResult(false, 'Validation Error: ' . $e->getMessage());
                    Capsule::connection()->transaction(function () use ($toolCallRecord, $result, $task, $toolCall): void {
                        $toolCallRecord->update([
                            'status'         => 'APPROVED',
                            'result_content' => $result->content,
                            'executed_at'    => date('Y-m-d H:i:s'),
                        ]);
                        $this->appendHistory(
                            taskId: $task->id,
                            role: 'tool',
                            content: $result->content,
                            toolCallId: $toolCall->providerCallId,
                            toolName: $toolCall->toolName,
                        );
                    });
                    continue;
                }

                if (!$requiresApproval) {
                    $result = $this->safeExecute($toolInstance, $toolCall->arguments, $agent->id, $task->id, $task->user_id);

                    Capsule::connection()->transaction(function () use ($toolCallRecord, $result, $task, $toolCall): void {
                        $toolCallRecord->update([
                            'status'         => 'APPROVED',
                            'result_content' => $result->content,
                            'result_data'    => $result->data ? json_encode($result->data, JSON_THROW_ON_ERROR) : null,
                            'executed_at'    => date('Y-m-d H:i:s'),
                        ]);
                        $this->appendHistory(
                            taskId: $task->id,
                            role: 'tool',
                            content: $result->content,
                            toolCallId: $toolCall->providerCallId,
                            toolName: $toolCall->toolName,
                        );
                    });
                } else {
                    $pendingApproval[] = $toolCall;
                }
            } catch (Throwable $e) {
                $this->appendHistory(
                    taskId: $task->id,
                    role: 'tool',
                    content: 'System Error: ' . $e->getMessage(),
                    toolCallId: $toolCall->providerCallId,
                    toolName: $toolCall->toolName,
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

        $taskData = [
            'id'         => $task->id,
            'status'     => $task->status,
            'step_count' => $task->step_count,
            'tool_calls' => $task->toolCalls->map(fn(ToolCallModel $tc) => [
                'id'                    => $tc->id,
                'tool_name'             => $tc->tool_name,
                'tool_type'             => $tc->tool_type,
                'operation'             => $tc->operation,
                'operation_description' => $tc->operation_description,
                'human_description'     => $tc->human_description,
                'status'                => $tc->status,
                'proposed_arguments'    => $tc->proposed_arguments,
                'approved_arguments'    => $tc->approved_arguments,
                'result_content'        => $tc->result_content,
                'executed_at'           => $tc->executed_at?->toIso8601String(),
            ])->all(),
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

    private function safeExecute(
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
            $result = $toolInstance->execute($arguments, $agentId, $userId);

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

    private function resolveRequiresApproval(object $toolInstance, string $toolClass, int $agentId, array|object $arguments = []): bool
    {
        if (is_object($arguments)) {
            $arguments = (array) $arguments;
        }

        $usesOperations = in_array(HasOperations::class, class_uses_recursive($toolClass), true);

        if ($usesOperations) {
            $operationName = $toolInstance->getOperationName($arguments);

            // Check per-operation override first
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

            // Fall back to agent-level auto_approve setting
            $agentTool = AgentTool::where('agent_id', $agentId)
                ->where('tool_class', $toolClass)
                ->first();
            if ($agentTool !== null) {
                $autoApproveRaw = $agentTool->getRawOriginal('auto_approve');
                if ($autoApproveRaw !== null) {
                    return !(bool) $autoApproveRaw;
                }
            }

            return $toolInstance->requiresApprovalByDefault($operationName);
        }

        throw new RuntimeException("Tool '{$toolClass}' does not use HasOperations trait.");
    }

    private function isOperationEnabled(object $toolInstance, string $operationName, int $agentId): bool
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
        $rows = TaskHistory::where('task_id', $taskId)
            ->orderBy('sequence')
            ->get();

        $messages = [];
        $lastSummarySeqEnd = -1;

        foreach ($rows as $row) {
            if ($row->role === 'summary' && $row->summarized_sequence_range !== null) {
                if (preg_match('/^(\d+)-(\d+)$/', $row->summarized_sequence_range, $m)) {
                    $rangeEnd = (int) $m[2];
                    // Remove any previously-added messages whose sequence is in this range
                    // (but preserve other summaries — they have their own _seq)
                    $messages = array_values(array_filter(
                        $messages,
                        static fn(array $msg): bool => ($msg['_seq'] ?? -1) > $rangeEnd || ($msg['role'] ?? '') === 'summary',
                    ));
                    $lastSummarySeqEnd = $rangeEnd;
                }
                $messages[] = [
                    'role'    => 'summary',
                    'content' => $row->content,
                ];
                $messages[count($messages) - 1]['_seq'] = $row->sequence;
                continue;
            }

            if ($row->sequence > $lastSummarySeqEnd) {
                if ($row->role === 'tool') {
                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $row->tool_call_id,
                        'name'         => $row->tool_name,
                        'content'      => $row->content,
                    ];
                } elseif ($row->role === 'assistant' && $row->tool_call_payload !== null) {
                    $toolCallsData = json_decode($row->tool_call_payload, true);
                    foreach ($toolCallsData as &$tc) {
                        if (array_key_exists('arguments', $tc['function'])) {
                            $args = $tc['function']['arguments'];
                            $decodedArgs = is_string($args) ? (json_decode($args, true) ?? []) : (array) $args;
                            if (empty($decodedArgs)) {
                                $tc['function']['arguments'] = '{}';
                            }
                        }
                    }
                    unset($tc);
                    $messages[] = [
                        'role'       => 'assistant',
                        'content'    => null,
                        'tool_calls' => $toolCallsData,
                    ];
                } else {
                    $messages[] = [
                        'role'    => $row->role,
                        'content' => $row->content,
                    ];
                }
                $messages[count($messages) - 1]['_seq'] = $row->sequence;
            }
        }

        foreach ($messages as &$msg) {
            unset($msg['_seq']);
        }
        unset($msg);

        return $messages;
    }

    private function buildLlmConfigBlock(array $llmSettings): string
    {
        if ($llmSettings === []) {
            return '';
        }

        $lines = [];
        foreach ($llmSettings as $setting) {
            $display = $setting['value'] === null || $setting['value'] === ''
                ? '(not configured)'
                : (string) $setting['value'];
            $lines[] = '- ' . $setting['label'] . ': ' . $display;
        }

        return "\n[Effective Configuration]\n" . implode("\n", $lines);
    }

    private function buildToolDefinitions(array $enabledClasses, int $agentId, ?int $userId = null): array
    {
        $defs = [];

        // Fetch all operation overrides for this agent in one query.
        $overrides = AgentToolOperationOverride::where('agent_id', $agentId)
            ->get()
            ->keyBy(fn($row) => $row->tool_class . '::' . $row->operation);

        foreach ($this->toolInstances as $instance) {
            $toolClass = get_class($instance);

            if (!in_array($toolClass, $enabledClasses, true)) {
                continue;
            }

            $ref   = new ReflectionClass($instance);
            $attrs = $ref->getAttributes(Tool::class);

            if ($attrs === []) {
                continue;
            }

            /** @var Tool $toolAttr */
            $toolAttr = $attrs[0]->newInstance();

            $qualifiedName = $this->qualifiedToolName($toolClass, $toolAttr->name);

            $usesOperations = in_array(HasOperations::class, class_uses_recursive($toolClass), true);

            if ($usesOperations) {
                $schema = $instance->getParametersSchema();
                $allowedOps = [];

                foreach ($instance->getOperations() as $op) {
                    $key = $toolClass . '::' . $op->name;
                    $row = $overrides->get($key);

                    if ($row !== null && $row->enabled === 0) {
                        continue;
                    }
                    if ($row !== null && $row->enabled === 1) {
                        $allowedOps[] = $op->name;
                        continue;
                    }
                    if ($op->enabledByDefault) {
                        $allowedOps[] = $op->name;
                    }
                }

                if ($allowedOps === []) {
                    continue;
                }

                $filteredSchema = $this->filterSchemaForOperations($schema, $allowedOps);

                $llmSettings = $this->toolConfigService !== null
                    ? $this->toolConfigService->getLlmToolSettings($toolClass, $agentId, $userId)
                    : [];
                $configBlock = $this->buildLlmConfigBlock($llmSettings);

                $defs[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $qualifiedName,
                        'description' => $toolAttr->description . $configBlock,
                        'parameters'  => $filteredSchema,
                    ],
                ];
            } else {
                $schema = $instance->getParametersSchema();

                if (isset($schema['properties']) && $schema['properties'] === []) {
                    $schema['properties'] = (object) [];
                }

                $llmSettings = $this->toolConfigService !== null
                    ? $this->toolConfigService->getLlmToolSettings($toolClass, $agentId, $userId)
                    : [];
                $configBlock = $this->buildLlmConfigBlock($llmSettings);

                $defs[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $qualifiedName,
                        'description' => $toolAttr->description . $configBlock,
                        'parameters'  => $schema,
                    ],
                ];
            }
        }

        return $defs;
    }

    private function filterSchemaForOperations(array $schema, array $allowedOps): array
    {
        $allowedOpsSet = array_flip($allowedOps);

        // properties may be a stdClass (from json_decode('{}')) or an array.
        $properties = $schema['properties'] ?? [];
        if (is_object($properties)) {
            $properties = (array) $properties;
        }

        if (isset($properties['action']['enum'])) {
            $properties['action']['enum'] = array_values(array_filter(
                $properties['action']['enum'],
                static fn($op) => isset($allowedOpsSet[$op]),
            ));
        }
        // Operation-specific params are kept — the LLM only calls allowed ops anyway.
        $schema['properties'] = (object) $properties;

        return $schema;
    }

    private function callTraitMethod(object $object, string $method, array $args): mixed
    {
        /** @var callable */
        $callable = [$object, $method];
        return $callable(...$args);
    }

    private function resolveToolByName(string $toolName): ToolInterface
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

        throw new RuntimeException("No tool registered with name '{$toolName}'.");
    }

    private function qualifiedToolName(string $toolClass, string $plainName): string
    {
        if ($this->pluginLoader !== null) {
            foreach ($this->pluginLoader->getPlugins() as $slug => $plugin) {
                if (in_array($toolClass, $plugin->tools(), true)) {
                    return "{$slug}:{$plainName}";
                }
            }
        }

        return $plainName;
    }

    private function appendHistory(
        int     $taskId,
        string  $role,
        ?string $content,
        ?string $toolCallId      = null,
        ?string $toolName        = null,
        ?string $toolCallPayload = null,
        int     $inputTokens     = 0,
        int     $outputTokens    = 0,
        ?string $reasoning       = null,
    ): void {
        $row = [
            'task_id'           => $taskId,
            'role'              => $role,
            'content'           => $content,
            'tool_call_id'      => $toolCallId,
            'tool_name'         => $toolName,
            'tool_call_payload' => $toolCallPayload,
            'input_tokens'      => $inputTokens,
            'output_tokens'     => $outputTokens,
        ];

        // Write reasoning unconditionally as the column is now part of the base schema
        if ($reasoning !== null) {
            $row['reasoning'] = $reasoning;
        }

        Capsule::connection()->transaction(function () use ($taskId, $row) {
            $nextSeq = TaskHistory::where('task_id', $taskId)->lockForUpdate()->max('sequence') ?? -1;
            $row['sequence'] = $nextSeq + 1;
            TaskHistory::create($row);
        });
    }

    private function classifyError(Throwable $e): string
    {
        if ($e instanceof LLMRateLimitException) {
            return 'RATE_LIMIT';
        }

        if ($e instanceof LLMRetryableException) {
            $msg = $e->getMessage();
            if (str_contains($msg, '529')) {
                return 'SERVER_OVERLOADED';
            }
            if (str_contains($msg, '520') || str_contains($msg, '500')) {
                return 'SERVER_ERROR';
            }
            return 'GATEWAY_ERROR';
        }

        if ($e instanceof LLMProviderException) {
            $msg = $e->getMessage();
            if (str_contains($msg, '401') || str_contains($msg, '403')) {
                return 'AUTH_ERROR';
            }
            if (str_contains($msg, '400')) {
                return 'BAD_REQUEST';
            }
            if ($e->isRetryable()) {
                return 'GATEWAY_ERROR';
            }
        }

        if ($e instanceof TimeoutExceptionInterface) {
            return 'LLM_TIMEOUT';
        }

        return 'UNKNOWN';
    }

    private function friendlyMessages(): array
    {
        return [
            'RATE_LIMIT'        => 'The AI service is busy. Try again in a moment.',
            'SERVER_OVERLOADED' => 'The AI service is under high load. Try again shortly.',
            'SERVER_ERROR'      => 'The AI service encountered an error. Please try again.',
            'GATEWAY_ERROR'     => 'The AI service is temporarily unavailable. Try again shortly.',
            'AUTH_ERROR'        => 'API authentication failed. Please check your API key.',
            'LLM_TIMEOUT'       => 'The AI request timed out. Check your model or increase the timeout setting.',
            'BAD_REQUEST'           => 'Invalid request. Please check your agent configuration.',
            'NO_LLM_CONFIGURATION'  => 'No LLM configuration set. Please configure an LLM driver or set a global default.',
            'TOOL_ERROR'           => 'A tool encountered an error. Check the task history for details.',
            'UNKNOWN'           => 'An unexpected error occurred. Please try again.',
        ];
    }

    private function resolveLlmConfig(Agent $agent): array
    {
        $defaults = [
            'context_window' => 128000,
            'max_tokens_output' => 4096,
            'temperature' => 0.7,
        ];

        $configId = $agent->llm_driver_config_id;

        if ($configId !== null) {
            $config = LLMDriverConfiguration::find($configId);
            if ($config !== null) {
                return [
                    'context_window' => $config->context_window ?? $defaults['context_window'],
                    'max_tokens_output' => $config->max_tokens_output ?? $defaults['max_tokens_output'],
                    'temperature' => $this->getTemperatureFromSettings($config, $defaults['temperature']),
                ];
            }
        }

        // Fall back to user preference — in async context, agent->user_id is the user context
        $preference = LLMDriverConfiguration::whereHas('userPreference', static fn($q) => $q->where('user_id', $agent->user_id))->first();
        if ($preference !== null) {
            return [
                'context_window' => $preference->context_window ?? $defaults['context_window'],
                'max_tokens_output' => $preference->max_tokens_output ?? $defaults['max_tokens_output'],
                'temperature' => $this->getTemperatureFromSettings($preference, $defaults['temperature']),
            ];
        }

        $globalDefault = LLMDriverConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->first();

        if ($globalDefault !== null) {
            return [
                'context_window' => $globalDefault->context_window ?? $defaults['context_window'],
                'max_tokens_output' => $globalDefault->max_tokens_output ?? $defaults['max_tokens_output'],
                'temperature' => $this->getTemperatureFromSettings($globalDefault, $defaults['temperature']),
            ];
        }

        throw new RuntimeException('No LLM configuration set for this agent. Set a preferred config or ensure a global default exists.');
    }

    private function getTemperatureFromSettings(LLMDriverConfiguration $config, float $default): float
    {
        try {
            $settings = $this->llmConfigService->decryptSettings($config->driver_class, $config->settings ?? '');
            return isset($settings['temperature']) && $settings['temperature'] !== ''
                ? (float) $settings['temperature']
                : $default;
        } catch (Throwable) {
            return $default;
        }
    }

    private function scheduleAutoRetry(Task $failedTask, string $errorCode): void
    {
        if (!in_array($errorCode, self::RETRYABLE_ERROR_CODES, true)) {
            return;
        }

        /** @var Agent|null $agent */
        $agent = Agent::find($failedTask->agent_id);
        if ($agent === null) {
            return;
        }
        $retryAfterMinutes = $agent->retry_after_minutes ?? 0;
        $maxRetries = $agent->max_retries ?? 0;
        if ($retryAfterMinutes <= 0 || $maxRetries <= 0) {
            return;
        }

        $rootTaskId = $failedTask->retry_of_task_id ?? $failedTask->id;
        $retryCount = (int) ($failedTask->retry_count ?? 0) + 1;

        if ($retryCount > $maxRetries) {
            return;
        }

        try {
            $retryTask = $this->start($agent->id, $failedTask->user_prompt, $failedTask->max_steps);
            $retryTask->update([
                'retry_of_task_id' => $rootTaskId,
                'retry_count'      => $retryCount,
                'retry_after'      => date('Y-m-d H:i:s', time() + $retryAfterMinutes * 60),
                'status'           => 'QUEUED',
            ]);

            $failedTask->update([
                'retry_after' => $retryTask->retry_after,
            ]);

            $this->notificationService?->notifyRetryQueued($retryTask, $retryCount, $maxRetries);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to schedule auto-retry', [
                'task_id'          => $failedTask->id,
                'exception_class'  => get_class($e),
                'message'          => $e->getMessage(),
            ]);
        }
    }
}

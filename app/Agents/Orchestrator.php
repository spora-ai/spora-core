<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
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
use Spora\Services\NotificationService;
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
     * @param  list<object>         $toolInstances      Instances of ToolInterface.
     * @param  ?LoggerInterface     $logger             Optional PSR-3 logger. When null, all log calls
     *                                                    are silently skipped — no behaviour change.
     * @param  ?NotificationService $notificationService Optional notification service for task events.
     */
    public function __construct(
        private readonly DriverFactory              $driverFactory,
        private readonly LLMConfigService|null    $llmConfigService = null,
        private readonly array                      $toolInstances = [],
        private readonly ?LoggerInterface           $logger         = null,
        private readonly WorkerMode                 $workerMode     = WorkerMode::Sync,
        private readonly ?NotificationService      $notificationService = null,
        private readonly ?PluginLoader              $pluginLoader   = null,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function start(int $agentId, string $userPrompt, int $maxSteps = 10, ?int $parentTaskId = null): Task
    {
        $agent = Agent::findOrFail($agentId);

        $task = Task::create([
            'agent_id'      => $agentId,
            'user_id'       => $agent->user_id,
            'status'        => $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED',
            'user_prompt'   => $userPrompt,
            'step_count'    => 0,
            'max_steps'     => $maxSteps,
            'parent_task_id' => $parentTaskId,
        ]);

        // Seed the conversation with the user's prompt as the first history row.
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
        // Phase 1 — short claim transaction: lock the task row, validate state, claim this step,
        // and read all data needed for the LLM call. Lock is released when the transaction commits,
        // before any network I/O, so the DB connection is not held idle during the LLM round-trip.
        $context = null;
        Capsule::connection()->transaction(function () use ($taskId, &$context) {
            $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();

            if ($task->status !== 'RUNNING') {
                return;
            }

            // Each tick() represents one full Agent lifecycle turn (Think → Act).
            // Increment exactly once so N parallel tools in a single turn count as 1 step.
            if ($task->step_count >= $task->max_steps) {
                $task->status         = 'FAILED';
                $task->failure_reason = 'Max steps reached.';
                $task->save();
                return;
            }

            $task->step_count++;
            $task->save();

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
                    tools: $this->buildToolDefinitions($enabledClasses, $agent->id),
                    contextWindow: $contextWindow,
                    maxTokens: $maxTokensOutput,
                    temperature: $temperature,
                ),
            ];
        });

        if ($context === null) {
            return; // Task was not RUNNING (or hit max_steps) — nothing to do.
        }

        // Phases 2 and 3 run outside any transaction. On unexpected failure, mark the task
        // FAILED before re-throwing so it never remains stuck in RUNNING indefinitely.
        try {
            // Phase 2 — LLM call: no DB lock held during I/O.
            $response = $this->driverFactory->makeFromAgent($context['agent'])->complete($context['request']);

            // Phase 3 — write results: each tick step's writes are their own atomic unit,
            // independent of any other tick step. This prevents a failure in a later step from
            // rolling back history rows already committed by an earlier step.
            $task           = $context['task'];
            $agent          = $context['agent'];
            $enabledClasses = $context['enabledClasses'];

            if ($response->hasToolCalls()) {
                // Append ONE assistant history row carrying ALL tool calls as a JSON array.
                // This matches the OpenAI/Anthropic wire format so buildMessages() reconstructs
                // the conversation correctly for parallel tool call providers.
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
                                // Normalize empty array [] to {} to satisfy strict providers
                                // (e.g. LM Studio, MiniMax) that require arguments to be an object
                                // when the schema declares type "object" with no required properties.
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
                // Pure text response — task is complete.
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

                // Skip task_completed notification if this task was triggered by a scheduled run —
                // notifyScheduledRunCompleted already fired in WorkerRunCommand.
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

            // Check for context window error — try compaction + retry if possible
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
                // DB itself may be unavailable — nothing more we can do here.
            }

            throw $e;
        }
    }

    /**
     * Detect if a Throwable is a context window exceeded error.
     */
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

    /**
     * Attempt to compact history and retry once.
     * Returns null on success (retry succeeded), rethrows on failure.
     */
    private function tryCompactionAndRetry(?Task $task, ?Agent $agent, Throwable $originalError): void
    {
        if ($task === null || $agent === null) {
            throw $originalError;
        }

        $taskId = $task->id;

        // Check if there's history to compact — if not, this is a first-turn error
        $historyCount = TaskHistory::where('task_id', $taskId)->count();
        if ($historyCount <= 1) {
            // First turn — no history to compact, fail with specific message
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

        // Compact and retry
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

        // Retry the tick
        try {
            $this->tick($taskId);
        } catch (Throwable) {
            // Summarization also failed — fail with original error
            throw $originalError;
        }
    }

    /**
     * Compact oldest task_history messages by summarizing them with the LLM.
     * The summary row is inserted as role='summary' and replaces the summarized range.
     */
    private function compactHistory(int $taskId, int $maxTokensOutput, float $temperature, Agent $agent): void
    {
        // Fetch all history except last 5 turns (keep recent context)
        $allRows = TaskHistory::where('task_id', $taskId)
            ->orderBy('sequence')
            ->get();

        $keepCount = 5;
        if ($allRows->count() <= $keepCount + 1) {
            return; // Nothing worth compacting
        }

        $toSummarizeRows = $allRows->take($allRows->count() - $keepCount);

        $firstRow = $toSummarizeRows->first();
        $lastRow = $toSummarizeRows->last();
        $firstSeq = $firstRow !== null ? $firstRow->sequence : 0;
        $lastSeq = $lastRow !== null ? $lastRow->sequence : 0;

        // Build messages for summarization (only content, no tool payloads)
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
            // No text content to summarize — just delete the rows
            TaskHistory::where('task_id', $taskId)
                ->where('sequence', '>=', $firstSeq)
                ->where('sequence', '<=', $lastSeq)
                ->delete();
            return;
        }

        // Call LLM to summarize
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

        // Delete the summarized rows and insert the summary
        Capsule::connection()->transaction(function () use ($taskId, $firstSeq, $lastSeq, $summaryText) {
            TaskHistory::where('task_id', $taskId)
                ->where('sequence', '>=', $firstSeq)
                ->where('sequence', '<=', $lastSeq)
                ->delete();

            // Insert summary row at the position of the first deleted row
            TaskHistory::create([
                'task_id' => $taskId,
                'sequence' => $firstSeq,
                'role' => 'summary',
                'content' => $summaryText,
                'summarized_sequence_range' => "{$firstSeq}-{$lastSeq}",
            ]);

            // Re-sequence remaining rows so there are no gaps
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

    /**
     * Extract actual context window from an error if available.
     */
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
        Capsule::connection()->transaction(function () use ($taskId, $approvedBatch) {
            /** @var Task $task */
            $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();

            if ($task->status !== 'PENDING_APPROVAL' || $task->pending_state === null) {
                throw new RuntimeException("Task {$taskId} is not awaiting approval.");
            }

            $state = AgentState::fromJson($task->pending_state);

            // Clear pending state and restore RUNNING status before executing.
            $task->status        = 'RUNNING';
            $task->pending_state = null;
            $task->save();

            $this->logger?->info('Task resumed after approval', [
                'task_id' => $task->id,
                'approved_count' => count($approvedBatch),
            ]);

            // Build a map from providerCallId → approved arguments for O(1) lookup.
            $approvedMap = [];
            foreach ($approvedBatch as $item) {
                $approvedMap[$item['provider_call_id']] = $item['arguments'];
            }

            foreach ($state->pendingToolCalls as $pendingToolCall) {
                $approvedArgs = $approvedMap[$pendingToolCall->providerCallId] ?? $pendingToolCall->arguments;

                $toolInstance = $this->resolveToolByName($pendingToolCall->toolName);

                // Fix #4: Validate the human-edited arguments before trusting them.
                try {
                    SchemaValidator::validate($approvedArgs, $toolInstance->getParametersSchema());
                } catch (Throwable $e) {
                    $result = new ToolResult(false, 'Validation Error: ' . $e->getMessage());
                    // Skip execution, immediately inject failure
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

                // Fix #5: Safe execution — community plugins may throw.
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

            $task->save();
        });

        // Tick is called after the transaction commits so the LLM round-trip
        // does not hold the lockForUpdate open for its full duration.
        $this->tick($taskId);
    }

    public function reject(int $taskId, string $reason): void
    {
        Capsule::connection()->transaction(function () use ($taskId, $reason) {
            /** @var Task $task */
            $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();

            if ($task->status !== 'PENDING_APPROVAL' || $task->pending_state === null) {
                throw new RuntimeException("Task {$taskId} is not awaiting approval.");
            }

            $state = AgentState::fromJson($task->pending_state);

            // Reject every pending tool call in the batch.
            $providerCallIds = array_map(
                static fn(DriverToolCall $tc) => $tc->providerCallId,
                $state->pendingToolCalls,
            );

            ToolCallModel::where('task_id', $taskId)
                ->whereIn('provider_call_id', $providerCallIds)
                ->update(['status' => 'REJECTED']);

            // Inject one synthetic tool-result row per rejected call so the LLM
            // can reason about the refusal for each individual action.
            foreach ($state->pendingToolCalls as $pendingToolCall) {
                $this->appendHistory(
                    taskId: $task->id,
                    role: 'tool',
                    content: "Action rejected by user: {$reason}",
                    toolCallId: $pendingToolCall->providerCallId,
                    toolName: $pendingToolCall->toolName,
                );
            }

            $task->status        = 'RUNNING';
            $task->pending_state = null;
            $task->save();
        });

        // Tick is called after the transaction commits so the LLM round-trip
        // does not hold the lockForUpdate open for its full duration.
        $this->tick($taskId);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Process a batch of tool calls from a single LLM response turn.
     *
     * Immediately executes tools that are enabled and not requiring approval.
     * Collects any tools that need approval into a batch; if any exist,
     * the task is paused and the full batch is serialised into pending_state.
     *
     * @param  list<DriverToolCall>  $toolCalls
     * @param  list<string>          $enabledClasses
     */
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

                // Resolve operation name and description if tool uses HasOperations.
                $operationName        = 'default';
                $operationDescription = null;
                $usesOperations       = in_array(HasOperations::class, class_uses_recursive($toolClass), true);

                if ($usesOperations) {
                    $operationName        = $this->callTraitMethod($toolInstance, 'getOperationName', [$toolCall->arguments]);
                    $operationDescription = $this->callTraitMethod($toolInstance, 'getOperationDescription', [$operationName]);

                    // Check if this specific operation is enabled for this agent.
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

                // Persist the ToolCall record so the UI can surface it immediately.
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
                    continue; // Skip execution and don't pause for approval
                }

                if (!$requiresApproval) {
                    // Execute immediately — tool is auto-approved.
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
                // If resolving the tool fails (hallucinated) or validating it fails, log the error to the LLM directly
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
            // All tools in this batch were executed immediately — continue the loop.
            $task->save();
            $this->tick($task->id);
        } else {
            // At least one tool needs approval — pause the task.
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
        }
    }

    /**
     * Execute a tool, catching any Throwable thrown by community plugin bugs.
     * The error is encoded into a ToolResult so the Orchestrator loop survives
     * and the LLM sees the failure on the next tick.
     *
     * Logging behaviour (controlled by SPORA_LOG_LEVEL):
     *   DEBUG — every dispatch is logged, including full arguments (may contain PII —
     *           only enable DEBUG in environments where log storage is trusted and
     *           data-retention obligations have been considered).
     *   ERROR — logged on ToolResult failure (success=false) or unhandled exception.
     *           Arguments are intentionally EXCLUDED from ERROR logs to prevent PII
     *           (email addresses, search queries, message bodies) from reaching
     *           aggregators that may have broader access or retention than DEBUG logs.
     */
    private function safeExecute(
        ToolInterface $toolInstance,
        array $arguments,
        int $agentId,
        int $taskId,
        ?int $userId = null,
    ): ToolResult {
        // Resolve the canonical tool name from the #[Tool] attribute for log context.
        $ref      = new ReflectionClass($toolInstance);
        $attrs    = $ref->getAttributes(Tool::class);
        $toolName = $attrs !== [] ? $attrs[0]->newInstance()->name : get_class($toolInstance);

        // DEBUG: log every invocation before execution.
        // ⚠ Arguments may contain PII — see method docblock before lowering log level.
        $this->logger?->debug('Tool dispatch', [
            'tool'      => $toolName,
            'agent_id'  => $agentId,
            'task_id'   => $taskId,
            'arguments' => $arguments,
        ]);

        try {
            $result = $toolInstance->execute($arguments, $agentId, $userId);

            if (!$result->success) {
                // ERROR: tool reported a logical failure (bad API key, empty result, etc.).
                // Arguments are NOT included here — see method docblock for PII rationale.
                $this->logger?->error('Tool returned failure', [
                    'tool'     => $toolName,
                    'agent_id' => $agentId,
                    'task_id'  => $taskId,
                    'content'  => $result->content,
                ]);
            }

            return $result;
        } catch (Throwable $e) {
            // ERROR: unhandled exception from the tool (likely a plugin bug).
            // Arguments are NOT included here — see method docblock for PII rationale.
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

    /**
     * Resolve whether the tool requires human approval before execution.
     *
     * Precedence for operation-aware tools (uses HasOperations):
     *   1. agent_tool_operation_overrides.default_requires_approval for this agent + tool_class + operation
     *   2. #[ToolOperation(name:)].requiresApprovalByDefault on the operation
     *
     *
     * Returns true → pause for approval, false → execute immediately.
     *
     * @param  array<string, mixed> $arguments  Arguments from the LLM tool call.
     */
    private function resolveRequiresApproval(object $toolInstance, string $toolClass, int $agentId, array|object $arguments = []): bool
    {
        if (is_object($arguments)) {
            $arguments = (array) $arguments;
        }

        $usesOperations = in_array(HasOperations::class, class_uses_recursive($toolClass), true);

        if ($usesOperations) {
            $operationName = $toolInstance->getOperationName($arguments);

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

            // No per-operation override — fall back to the agent_tools.auto_approve flag.
            // This is the value the UI toggles when enabling/disabling auto-approve per tool.
            $agentTool = AgentTool::where('agent_id', $agentId)
                ->where('tool_class', $toolClass)
                ->first();
            if ($agentTool !== null) {
                $autoApproveRaw = $agentTool->getRawOriginal('auto_approve');
                if ($autoApproveRaw !== null) {
                    return !(bool) $autoApproveRaw; // auto_approve=1 → false (no approval), auto_approve=0 → true (approval required)
                }
            }

            return $toolInstance->requiresApprovalByDefault($operationName);
        }

        // All tools now use HasOperations; this fallback should never be reached.
        throw new RuntimeException("Tool '{$toolClass}' does not use HasOperations trait.");
    }

    /**
     * Check whether a specific operation is enabled for an agent.
     *
     * Precedence:
     *   1. agent_tool_operation_overrides.enabled for this agent + tool_class + operation
     *   2. #[ToolOperation(name:)].enabledByDefault on the operation
     */
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

    /**
     * Build the OpenAI-compatible message array from task_history rows.
     *
     * The tool_call_payload column stores a JSON-encoded array of tool call objects
     * (even when only one tool was called) so that parallel tool call turns round-trip
     * correctly through the conversation history.
     *
     * @return list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string, name?: string}>
     */
    private function buildMessages(int $taskId): array
    {
        $rows = TaskHistory::where('task_id', $taskId)
            ->orderBy('sequence')
            ->get();

        $messages = [];
        $lastSummarySeqEnd = -1;

        foreach ($rows as $row) {
            // When we encounter a summary row, record the range it covers.
            // All rows with sequence <= lastSummarySeqEnd have already been added
            // as "recent" history — once we see a summary covering those rows,
            // we must remove them since the summary replaces them.
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

            // If this row is after the last summary's range, it's part of "recent" history — include it
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
                // Tag this message with its sequence so it can be filtered out later
                // if a summary covering this position is encountered
                $messages[count($messages) - 1]['_seq'] = $row->sequence;
            }
        }

        // Strip internal _seq tags before returning
        foreach ($messages as &$msg) {
            unset($msg['_seq']);
        }
        unset($msg);

        return $messages;
    }

    /**
     * Build the tool definitions array for the LLM request from registered tool instances.
     *
     * Plugin-contributed tools are prefixed with "{slug}:" to ensure global uniqueness.
     * Core tools are sent with their plain name.
     *
     * @param  list<string> $enabledClasses
     * @return list<array{type: "function", function: array{name: string, description: string, parameters: array}}>
     */
    private function buildToolDefinitions(array $enabledClasses, int $agentId): array
    {
        $defs = [];

        // Fetch all operation overrides for this agent in one query.
        $overrides = AgentToolOperationOverride::where('agent_id', $agentId)
            ->whereIn('tool_class', $enabledClasses)
            ->get()
            ->groupBy(fn($row) => $row->tool_class)
            ->map(fn($group) => $group->keyBy('operation'));

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

            // Prefix with plugin slug if this is a plugin tool, else plain name.
            $qualifiedName = $this->qualifiedToolName($toolClass, $toolAttr->name);

            // Check if tool uses HasOperations — if so, filter to only enabled operations.
            $usesOperations = in_array(HasOperations::class, class_uses_recursive($toolClass), true);

            if ($usesOperations) {
                $schema = $instance->getParametersSchema();
                $allowedOps = [];

                foreach ($instance->getOperations() as $op) {
                    $opOverride = $overrides[$toolClass][$op->name] ?? null;

                    // Resolve enabled: override takes precedence, else use enabledByDefault.
                    if ($opOverride !== null && $opOverride->enabled !== null) {
                        $isEnabled = (bool) $opOverride->enabled;
                    } else {
                        $isEnabled = $op->enabledByDefault;
                    }

                    if ($isEnabled) {
                        $allowedOps[] = $op->name;
                    }
                }

                // If no operations are enabled, skip this tool entirely.
                if ($allowedOps === []) {
                    continue;
                }

                // Filter the schema's action enum and parameter descriptions to only the enabled ops.
                $filteredSchema = $this->filterSchemaForOperations($schema, $allowedOps);

                $defs[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $qualifiedName,
                        'description' => $toolAttr->description,
                        'parameters'  => $filteredSchema,
                    ],
                ];
            } else {
                // Single-operation tool: include as-is.
                $schema = $instance->getParametersSchema();

                // Normalize empty "properties" from [] to (object)[] so it encodes as {} in JSON.
                if (isset($schema['properties']) && $schema['properties'] === []) {
                    $schema['properties'] = (object) [];
                }

                $defs[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $qualifiedName,
                        'description' => $toolAttr->description,
                        'parameters'  => $schema,
                    ],
                ];
            }
        }

        return $defs;
    }

    /**
     * Filter a tool schema to only include the given operation names.
     * - Restricts the `action` enum to only allowed operations.
     * - Removes parameters that only apply to excluded operations.
     */
    private function filterSchemaForOperations(array $schema, array $allowedOps): array
    {
        $allowedOpsSet = array_flip($allowedOps);

        // properties may be a stdClass (from json_decode('{}')) or an array.
        // Normalize to array for consistent access, then back to stdClass at the end.
        $properties = $schema['properties'] ?? [];
        if (is_object($properties)) {
            $properties = (array) $properties;
        }

        // Restrict action enum.
        if (isset($properties['action']['enum'])) {
            $properties['action']['enum'] = array_values(array_filter(
                $properties['action']['enum'],
                static fn($op) => isset($allowedOpsSet[$op]),
            ));
        }

        // Remove parameters that are only used by excluded operations.
        // Parameters shared by multiple ops (like 'action') are kept.
        // Operation-specific params: we keep them all for now since the LLM
        // will only call with allowed ops. Cleaning unused params would require
        // mapping which param belongs to which op — keep it simple.
        $schema['properties'] = (object) $properties;

        return $schema;
    }

    /**
     * Call a trait method on an object via dynamic dispatch.
     *
     * @param  object       $object
     * @param  string       $method
     * @param  array<mixed> $args
     * @return mixed
     */
    private function callTraitMethod(object $object, string $method, array $args): mixed
    {
        /** @var callable */
        $callable = [$object, $method];
        return $callable(...$args);
    }

    /**
     * Find the tool instance matching the given tool name (from #[Tool(name:)] attribute).
     *
     * Accepts both plain names ("web_search") and namespaced names ("my-plugin:web_search").
     * For namespaced names the slug is stripped before lookup, so the class's #[Tool] attribute
     * always holds the plain name — only the LLM-facing wire format is namespaced.
     *
     * @throws RuntimeException  When no matching tool is registered.
     */
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

    /**
     * Return the LLM-facing tool name, prefixed with the plugin slug if the tool
     * was contributed by a plugin. Core tools use their plain name.
     *
     * @param  class-string $toolClass
     * @param  string       $plainName  From #[Tool(name:)]
     * @return string                 e.g. "my-plugin:web_search" or "web_search"
     */
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

    /**
     * Append one message to task_history with auto-incrementing sequence.
     */
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

    /**
     * Classify a Throwable into a short error code for storage on the task.
     */
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

    /**
     * Map a short error code to a human-readable message shown in the UI.
     *
     * @return array<string, string>
     */
    private function friendlyMessages(): array
    {
        return [
            'RATE_LIMIT'        => 'The AI service is busy. Try again in a moment.',
            'SERVER_OVERLOADED' => 'The AI service is under high load. Try again shortly.',
            'SERVER_ERROR'      => 'The AI service encountered an error. Please try again.',
            'GATEWAY_ERROR'     => 'The AI service is temporarily unavailable. Try again shortly.',
            'AUTH_ERROR'        => 'API authentication failed. Please check your API key.',
            'LLM_TIMEOUT'       => 'The AI request timed out. Check your model or increase the timeout setting.',
            'BAD_REQUEST'       => 'Invalid request. Please check your agent configuration.',
            'TOOL_ERROR'        => 'A tool encountered an error. Check the task history for details.',
            'UNKNOWN'           => 'An unexpected error occurred. Please try again.',
        ];
    }

    /**
     * Resolve LLM configuration for an agent (context_window, max_tokens_output, temperature).
     *
     * @return array{context_window: int, max_tokens_output: int, temperature: float}
     */
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

        // Fall back to global default
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

    /**
     * Schedule an automatic retry if the error is retryable and the agent has retry config.
     *
     * All retry tasks link to the ROOT original task (not immediate parent) so that
     * the entire chain can be cancelled with a single WHERE clause:
     * WHERE retry_of_task_id = rootId AND retry_count >= N
     */
    private function scheduleAutoRetry(Task $failedTask, string $errorCode): void
    {
        if (!in_array($errorCode, self::RETRYABLE_ERROR_CODES, true)) {
            return; // Non-retryable error — user must click Retry manually
        }

        /** @var Agent|null $agent */
        $agent = Agent::find($failedTask->agent_id);
        if ($agent === null) {
            return;
        }
        $retryAfterMinutes = $agent->retry_after_minutes ?? 0;
        $maxRetries = $agent->max_retries ?? 0;
        if ($retryAfterMinutes <= 0 || $maxRetries <= 0) {
            return; // Auto-retry not configured for this agent
        }

        // Always link to the ROOT original task (enables single-query cancel)
        $rootTaskId = $failedTask->retry_of_task_id ?? $failedTask->id;
        $retryCount = (int) ($failedTask->retry_count ?? 0) + 1;

        if ($retryCount > $maxRetries) {
            return; // Max retries exceeded
        }

        try {
            $retryTask = $this->start($agent->id, $failedTask->user_prompt, $failedTask->max_steps);
            $retryTask->update([
                'retry_of_task_id' => $rootTaskId,
                'retry_count'      => $retryCount,
                'retry_after'      => date('Y-m-d H:i:s', time() + $retryAfterMinutes * 60),
                'status'           => 'QUEUED',
            ]);

            // Set retry_after on the root task so the UI can show a countdown immediately
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

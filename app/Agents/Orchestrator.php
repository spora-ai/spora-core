<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Spora\Agents\Messages\TickMessage;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Tools\Attributes\OutputTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\InputToolInterface;
use Spora\Tools\OutputToolInterface;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final class Orchestrator implements OrchestratorInterface
{
    /**
     * @param  list<object>       $toolInstances  Instances of InputToolInterface|OutputToolInterface.
     * @param  ?LoggerInterface   $logger         Optional PSR-3 logger. When null, all log calls
     *                                            are silently skipped — no behaviour change.
     */
    public function __construct(
        private readonly DriverFactory       $driverFactory,
        private readonly MessageBusInterface $bus,
        private readonly array               $toolInstances = [],
        private readonly ?LoggerInterface    $logger        = null,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function start(int $agentId, string $userPrompt, int $maxSteps = 10): Task
    {
        $agent = Agent::findOrFail($agentId);

        $task = Task::create([
            'agent_id'    => $agentId,
            'user_id'     => $agent->user_id,
            'status'      => 'RUNNING',
            'user_prompt' => $userPrompt,
            'step_count'  => 0,
            'max_steps'   => $maxSteps,
        ]);

        // Seed the conversation with the user's prompt as the first history row.
        $this->appendHistory($task->id, 'user', $userPrompt);

        $this->bus->dispatch(new TickMessage($task->id));

        return $task->fresh();
    }

    public function tick(int $taskId): void
    {
        $task = Task::findOrFail($taskId);

        if ($task->status !== 'RUNNING') {
            return;
        }

        // Each tick() represents one full Agent lifecycle turn (Think → Act).
        // Increment exactly once here so N parallel tools in a single turn count as 1 step.
        if ($task->step_count >= $task->max_steps) {
            $task->status         = 'FAILED';
            $task->failure_reason = 'Max steps reached.';
            $task->save();
            return;
        }

        $task->step_count++;
        $task->save();

        $agent = Agent::findOrFail($task->agent_id);

        // Build conversation messages from task_history.
        $messages = $this->buildMessages($task->id);

        // Fetch enabled tool classes for this agent
        $enabledClasses = AgentTool::where('agent_id', $agent->id)->pluck('tool_class')->toArray();

        // Build LLM tool definitions from registered tool instances.
        $toolDefs = $this->buildToolDefinitions($enabledClasses);

        $request = new LLMRequest(
            systemPrompt: 'You are a helpful AI assistant.',
            messages: $messages,
            tools: $toolDefs,
        );

        $response = $this->driverFactory->makeFromAgent($agent)->complete($request);

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
                            'arguments' => json_encode($tc->arguments, JSON_THROW_ON_ERROR),
                        ],
                    ], $response->toolCalls),
                    JSON_THROW_ON_ERROR,
                ),
                inputTokens: $response->inputTokens,
                outputTokens: $response->outputTokens,
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
            );

            $task->status         = 'COMPLETED';
            $task->final_response = $response->content;
            $task->save();
        }
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
                $result = $this->safeExecute($toolInstance, $approvedArgs, $state->agentId, $taskId);

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

            $this->bus->dispatch(new TickMessage($task->id));
        });
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

            $this->bus->dispatch(new TickMessage($task->id));
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Process a batch of tool calls from a single LLM response turn.
     *
     * Immediately executes InputTools and auto-approved OutputTools.
     * Collects any OutputTools that need approval into a batch; if any exist,
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

                $toolType         = $toolInstance instanceof OutputToolInterface ? 'output' : 'input';
                $requiresApproval = $this->resolveRequiresApproval($toolInstance, $toolClass, $agent->id);

                // Persist the ToolCall record so the UI can surface it immediately.
                $toolCallRecord = ToolCallModel::create([
                    'task_id'            => $task->id,
                    'agent_id'           => $agent->id,
                    'provider_call_id'   => $toolCall->providerCallId,
                    'tool_name'          => $toolCall->toolName,
                    'tool_class'         => $toolClass,
                    'tool_type'          => $toolType,
                    'status'             => 'PENDING_APPROVAL',
                    'proposed_arguments' => json_encode($toolCall->arguments, JSON_THROW_ON_ERROR),
                    'human_description'  => $toolInstance instanceof OutputToolInterface
                        ? $toolInstance->describeAction($toolCall->arguments)
                        : null,
                ]);

                try {
                    SchemaValidator::validate($toolCall->arguments, $toolInstance->getParametersSchema());
                } catch (Throwable $e) {
                    $result = new ToolResult(false, 'Validation Error: ' . $e->getMessage());
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
                    continue; // Skip execution and don't pause for approval
                }

                if ($toolInstance instanceof InputToolInterface || !$requiresApproval) {
                    // Fix #5: Safe execution — community plugins may throw.
                    $result = $this->safeExecute($toolInstance, $toolCall->arguments, $agent->id, $task->id);

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
            $this->bus->dispatch(new TickMessage($task->id));
        } else {
            // At least one OutputTool needs approval — pause the task.
            // step_count was already incremented for any immediately-executed tools.
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
        InputToolInterface|OutputToolInterface $toolInstance,
        array $arguments,
        int $agentId,
        int $taskId,
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
            $result = $toolInstance->execute($arguments, $agentId);

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
     *   1. AgentTool row override (null means "not set").
     *   2. Class-level #[OutputTool(requiresApproval:)] attribute (default: true).
     *   Returns true → pause for approval, false → execute immediately.
     */
    private function resolveRequiresApproval(object $toolInstance, string $toolClass, int $agentId): bool
    {
        if (!$toolInstance instanceof OutputToolInterface) {
            return false;
        }

        /** @var AgentTool|null $row */
        $row = AgentTool::where('agent_id', $agentId)->where('tool_class', $toolClass)->first();

        if ($row !== null) {
            $raw = $row->getRawOriginal('auto_approve');
            if ($raw !== null) {
                return !((bool) $raw); // auto_approve=1 → no approval needed → false
            }
        }

        // Fall back to class attribute.
        $ref   = new ReflectionClass($toolClass);
        $attrs = $ref->getAttributes(OutputTool::class);
        if ($attrs !== []) {
            $attr = $attrs[0]->newInstance();
            return $attr->requiresApproval;
        }

        return true; // safe default: require approval
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

        foreach ($rows as $row) {
            if ($row->role === 'tool') {
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $row->tool_call_id,
                    'name'         => $row->tool_name,
                    'content'      => $row->content,
                ];
            } elseif ($row->role === 'assistant' && $row->tool_call_payload !== null) {
                // tool_call_payload is a JSON array of tool call objects.
                $toolCallsData = json_decode($row->tool_call_payload, true);
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
        }

        return $messages;
    }

    /**
     * Build the tool definitions array for the LLM request from registered tool instances.
     *
     * @param  list<string> $enabledClasses
     * @return list<array{type: "function", function: array{name: string, description: string, parameters: array}}>
     */
    private function buildToolDefinitions(array $enabledClasses): array
    {
        $defs = [];

        foreach ($this->toolInstances as $instance) {
            if (!in_array(get_class($instance), $enabledClasses, true)) {
                continue;
            }

            $ref   = new ReflectionClass($instance);
            $attrs = $ref->getAttributes(Tool::class);

            if ($attrs === []) {
                continue;
            }

            /** @var Tool $toolAttr */
            $toolAttr = $attrs[0]->newInstance();

            $defs[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $toolAttr->name,
                    'description' => $toolAttr->description,
                    'parameters'  => $instance->getParametersSchema(),
                ],
            ];
        }

        return $defs;
    }

    /**
     * Find the tool instance matching the given tool name (from #[Tool(name:)] attribute).
     *
     * @throws RuntimeException  When no matching tool is registered.
     */
    private function resolveToolByName(string $toolName): InputToolInterface|OutputToolInterface
    {
        foreach ($this->toolInstances as $instance) {
            $ref   = new ReflectionClass($instance);
            $attrs = $ref->getAttributes(Tool::class);

            if ($attrs === []) {
                continue;
            }

            /** @var Tool $toolAttr */
            $toolAttr = $attrs[0]->newInstance();

            if ($toolAttr->name === $toolName) {
                return $instance;
            }
        }

        throw new RuntimeException("No tool registered with name '{$toolName}'.");
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
    ): void {
        $nextSeq = TaskHistory::where('task_id', $taskId)->max('sequence') ?? -1;

        TaskHistory::create([
            'task_id'           => $taskId,
            'sequence'          => $nextSeq + 1,
            'role'              => $role,
            'content'           => $content,
            'tool_call_id'      => $toolCallId,
            'tool_name'         => $toolName,
            'tool_call_payload' => $toolCallPayload,
            'input_tokens'      => $inputTokens,
            'output_tokens'     => $outputTokens,
        ]);
    }
}

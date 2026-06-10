<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\ToolCallSerializer;
use Throwable;

/**
 * Runs the three tick phases (claim → LLM call → write results) for the
 * orchestrator. Extracted so the orchestrator stays under the SonarQube
 * `php:S1448` method-count cap.
 *
 * Holds the orchestrator by reference to call back into `appendHistory`,
 * `tick`, `buildMessages`, `errorClassifier`, `contextWindowRecovery`,
 * and `retryScheduler` (mirrors {@see ToolCallExecutor}).
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
    ) {}
    public function runTick(int $taskId): void
    {
        $task = $this->lockRunningTaskForTick($taskId);
        if ($task === null) {
            return;
        }

        try {
            $context = $this->prepareTickContext($task);
        } catch (RuntimeException $e) {
            $this->orchestrator->errorClassifier->markTaskNoLlmConfiguration($taskId, $e);
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

        $llmConfig = $this->orchestrator->llmConfigResolver->resolveLlmConfig($agent);

        $request = new LLMRequest(
            systemPrompt: $this->resolveSystemPrompt($agent),
            messages: $this->orchestrator->buildMessages($task->id),
            tools: $this->orchestrator->toolDefinitionBuilder->buildToolDefinitions($enabledClasses, $agent->id, $agent->user_id),
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
        $this->orchestrator->appendHistory(
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
        $this->orchestrator->appendHistory(
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
            $this->orchestrator->retryScheduler->scheduleAutoRetry($failedTask, $errorCode);
        } catch (Throwable $e) {
            $this->logger?->warning('Auto-retry scheduling failed', [
                'task_id'   => $failedTask->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function handleToolCalls(Task $task, Agent $agent, array $toolCalls, array $enabledClasses): void
    {
        /** @var list<DriverToolCall> $pendingApproval */
        $pendingApproval = [];

        foreach ($toolCalls as $toolCall) {
            try {
                $disposition = $this->orchestrator->toolCallExecutor->executeOrQueue($toolCall, $agent, $task, $enabledClasses);

                if ($disposition === ToolCallDisposition::AwaitingApproval) {
                    $pendingApproval[] = $toolCall;
                }
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
            $this->publishIntermediateState($task);
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
}

<?php

declare(strict_types=1);

namespace Spora\Agents\ValueObjects;

use Spora\Drivers\ValueObjects\ToolCall;

/**
 * Snapshot of Orchestrator state at the moment an OutputTool call is intercepted.
 * Stored as JSON in tasks.pending_state. Reconstructed to resume after human approval.
 */
final readonly class AgentState
{
    public function __construct(
        public int      $taskId,
        public int      $agentId,

        /**
         * The exact tool call that triggered the pause.
         */
        public ToolCall $pendingToolCall,

        /**
         * Conversation history frozen at pause time.
         * Authoritative source for the resume path.
         *
         * @var list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string, name?: string}>
         */
        public array    $messageSnapshot,
        public int      $stepCount,
        public int      $maxSteps,

        /** ISO 8601 UTC timestamp. */
        public string   $pausedAt,
    ) {}

    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new static(
            taskId: $data['task_id'],
            agentId: $data['agent_id'],
            pendingToolCall: new ToolCall(
                providerCallId: $data['pending_tool_call']['provider_call_id'],
                toolName: $data['pending_tool_call']['tool_name'],
                arguments: $data['pending_tool_call']['arguments'],
            ),
            messageSnapshot: $data['message_snapshot'] ?? [],
            stepCount: $data['step_count'],
            maxSteps: $data['max_steps'],
            pausedAt: $data['paused_at'],
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'task_id'           => $this->taskId,
            'agent_id'          => $this->agentId,
            'pending_tool_call' => [
                'provider_call_id' => $this->pendingToolCall->providerCallId,
                'tool_name'        => $this->pendingToolCall->toolName,
                'arguments'        => $this->pendingToolCall->arguments,
            ],
            'message_snapshot'  => $this->messageSnapshot,
            'step_count'        => $this->stepCount,
            'max_steps'         => $this->maxSteps,
            'paused_at'         => $this->pausedAt,
        ], JSON_THROW_ON_ERROR);
    }
}

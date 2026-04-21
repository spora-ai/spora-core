<?php

declare(strict_types=1);

namespace Spora\Agents\ValueObjects;

use Spora\Drivers\ValueObjects\ToolCall;

/**
 * Snapshot of Orchestrator state at the moment one or more OutputTool calls are intercepted.
 * Stored as JSON in tasks.pending_state. Reconstructed to resume after human approval.
 */
final readonly class AgentState
{
    public function __construct(
        public int   $taskId,
        public int   $agentId,

        /**
         * All tool calls that triggered the pause (parallel tool calls may produce a batch).
         *
         * @var list<ToolCall>
         */
        public array $pendingToolCalls,

        /**
         * Conversation history frozen at pause time.
         * Authoritative source for the resume path.
         *
         * @var list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string, name?: string}>
         */
        public array  $messageSnapshot,
        public int    $stepCount,
        public int    $maxSteps,

        /** ISO 8601 UTC timestamp. */
        public string $pausedAt,
    ) {}

    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $pendingToolCalls = array_map(
            static function (array $tc): ToolCall {
                $args = $tc['arguments'] ?? [];
                // Defensive: if arguments came from a stdClass or other object, flatten to array.
                if (is_object($args)) {
                    $args = (array) $args;
                }

                return new ToolCall(
                    providerCallId: $tc['provider_call_id'],
                    toolName: $tc['tool_name'],
                    arguments: $args,
                );
            },
            $data['pending_tool_calls'],
        );

        return new static(
            taskId: $data['task_id'],
            agentId: $data['agent_id'],
            pendingToolCalls: $pendingToolCalls,
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
            'pending_tool_calls' => array_map(
                static fn(ToolCall $tc) => [
                    'provider_call_id' => $tc->providerCallId,
                    'tool_name'        => $tc->toolName,
                    'arguments'        => $tc->arguments,
                ],
                $this->pendingToolCalls,
            ),
            'message_snapshot'  => $this->messageSnapshot,
            'step_count'        => $this->stepCount,
            'max_steps'         => $this->maxSteps,
            'paused_at'         => $this->pausedAt,
        ], JSON_THROW_ON_ERROR);
    }
}

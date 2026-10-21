<?php

declare(strict_types=1);

namespace Tests\Feature;

use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Task;

/**
 * Scripted LLM driver for the `sub_agent_continue` end-to-end test.
 *
 * A continuation call has to name the child's task id, and that id is
 * assigned by the database — so this driver reads it back when it builds
 * the call instead of taking it from a pre-scripted response list.
 *
 * The call sequence is: the parent spawns, the child answers, the parent
 * continues, the child answers again, the parent closes.
 */
final class SubAgentContinueE2eScriptedDriver implements LLMDriverInterface
{
    private const SPAWN_PROMPT   = 'Please summarize the meeting notes file.';
    private const CONTINUE_PROMPT = 'change the heading';

    public int $callCount = 0;

    public function __construct(
        private readonly int $parentAgentId,
        private readonly int $childAgentId,
    ) {}

    public function complete(LLMRequest $request): LLMResponse
    {
        $this->callCount++;

        return match ($this->callCount) {
            1 => $this->toolCall('pc_spawn', [
                'op'              => 'sub_agent',
                'target_agent_id' => $this->childAgentId,
                'prompt'          => self::SPAWN_PROMPT,
            ]),
            2 => new LLMResponse('first child answer', [], 5, 3, 'cmp_child_1'),
            3 => $this->toolCall('pc_continue', [
                'op'      => 'sub_agent_continue',
                'task_id' => (string) $this->childTaskId(),
                'prompt'  => self::CONTINUE_PROMPT,
            ]),
            4 => new LLMResponse('second child answer', [], 5, 3, 'cmp_child_2'),
            default => new LLMResponse('All done.', [], 5, 3, 'cmp_parent_2'),
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function toolCall(string $providerCallId, array $arguments): LLMResponse
    {
        return new LLMResponse(
            content: null,
            toolCalls: [new DriverToolCall($providerCallId, 'sub_agent', $arguments)],
            inputTokens: 10,
            outputTokens: 5,
            completionId: 'cmp_tool_call',
        );
    }

    /**
     * The child task the parent spawned.
     */
    private function childTaskId(): int
    {
        $parentId = Task::where('agent_id', $this->parentAgentId)->value('id');
        if ($parentId === null) {
            return 0;
        }

        return (int) Task::where('parent_task_id', $parentId)->value('id');
    }

    public function getProviderName(): string
    {
        return 'mock';
    }

    public function getModelName(): string
    {
        return 'mock-model';
    }

    public function supportsImageInput(): bool
    {
        return false;
    }
}

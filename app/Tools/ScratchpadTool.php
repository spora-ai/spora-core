<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Models\AgentMemory;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(
    name: 'scratchpad',
    description: 'Provides a persistent Key-Value store where you can save long-term memory about the user or draft complex reports. Memories are persisted across all tasks for this agent.',
)]
#[ToolParameter(
    name: 'action',
    type: 'string',
    description: 'The action to perform: "read", "write", or "delete".',
    required: true,
    enum: ['read', 'write', 'delete']
)]
#[ToolParameter(
    name: 'key',
    type: 'string',
    description: 'The identifier for the memory (e.g. "user_preferences"). Limit to 100 characters, alphanumeric and underscores.',
    required: true,
)]
#[ToolParameter(
    name: 'value',
    type: 'string',
    description: 'The content to store. Required when action is "write".',
    required: false,
)]
final class ScratchpadTool implements InputToolInterface
{
    public function execute(array $arguments, int $agentId): ToolResult
    {
        $action = (string) ($arguments['action'] ?? '');
        $key    = (string) ($arguments['key'] ?? '');

        if ($key === '') {
            return new ToolResult(false, 'Error: Key is required.');
        }

        switch ($action) {
            case 'read':
                $memory = AgentMemory::where('agent_id', $agentId)->where('key', $key)->first();
                if ($memory) {
                    return new ToolResult(true, "Found memory [{$key}]:\n{$memory->value}");
                }
                return new ToolResult(false, "Memory [{$key}] not found.");

            case 'write':
                $value = (string) ($arguments['value'] ?? '');
                if ($value === '') {
                    return new ToolResult(false, 'Error: Value is required for write action.');
                }
                AgentMemory::updateOrCreate(
                    ['agent_id' => $agentId, 'key' => $key],
                    ['value' => $value]
                );
                return new ToolResult(true, "Successfully saved memory [{$key}].");

            case 'delete':
                $deleted = AgentMemory::where('agent_id', $agentId)->where('key', $key)->delete();
                if ($deleted) {
                    return new ToolResult(true, "Successfully deleted memory [{$key}].");
                }
                return new ToolResult(false, "Memory [{$key}] not found to delete.");

            default:
                return new ToolResult(false, 'Error: Invalid action. Must be read, write, or delete.');
        }
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'description' => 'The action to perform: "read", "write", or "delete".',
                    'enum'        => ['read', 'write', 'delete'],
                ],
                'key' => [
                    'type'        => 'string',
                    'description' => 'The identifier for the memory (e.g. "user_preferences").',
                ],
                'value' => [
                    'type'        => 'string',
                    'description' => 'The content to store. Required when action is "write".',
                ],
            ],
            'required' => ['action', 'key'],
        ];
    }
}

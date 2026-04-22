<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Models\AgentMemory;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(
    name: 'scratchpad',
    description: 'Provides a persistent Key-Value store where you can save long-term memory about the user or draft complex reports. Memories are persisted across all tasks for this agent.',
    displayName: 'Scratchpad',
    category: 'productivity',
)]
#[ToolOperation(name: 'read', description: 'Read a memory value by key', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'write', description: 'Write a memory value to a key', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'delete', description: 'Delete a memory by key', enabledByDefault: true, requiresApprovalByDefault: false)]
final class ScratchpadTool implements ToolInterface
{
    use HasOperations;

    public function execute(array $arguments, int $agentId): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'read'   => $this->read($arguments, $agentId),
            'write'  => $this->write($arguments, $agentId),
            'delete' => $this->delete($arguments, $agentId),
            default  => new ToolResult(false, 'Invalid action. Must be read, write, or delete.'),
        };
    }

    public function describeAction(array $arguments): string
    {
        $action = (string) ($arguments['action'] ?? $this->getOperationName($arguments));
        $key    = (string) ($arguments['key'] ?? '');
        return "Scratchpad {$action} on key: {$key}";
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

    public function read(array $arguments, int $agentId): ToolResult
    {
        $key = (string) ($arguments['key'] ?? '');

        if ($key === '') {
            return new ToolResult(false, 'Error: Key is required.');
        }

        $memory = AgentMemory::where('agent_id', $agentId)->where('key', $key)->first();
        if ($memory) {
            return new ToolResult(true, "Found memory [{$key}]:\n{$memory->value}");
        }
        return new ToolResult(false, "Memory [{$key}] not found.");
    }

    public function write(array $arguments, int $agentId): ToolResult
    {
        $key   = (string) ($arguments['key'] ?? '');
        $value = (string) ($arguments['value'] ?? '');

        if ($key === '') {
            return new ToolResult(false, 'Error: Key is required.');
        }
        if ($value === '') {
            return new ToolResult(false, 'Error: Value is required for write action.');
        }

        AgentMemory::updateOrCreate(
            ['agent_id' => $agentId, 'key' => $key],
            ['value' => $value],
        );
        return new ToolResult(true, "Successfully saved memory [{$key}].");
    }

    public function delete(array $arguments, int $agentId): ToolResult
    {
        $key = (string) ($arguments['key'] ?? '');

        if ($key === '') {
            return new ToolResult(false, 'Error: Key is required.');
        }

        $deleted = AgentMemory::where('agent_id', $agentId)->where('key', $key)->delete();
        if ($deleted) {
            return new ToolResult(true, "Successfully deleted memory [{$key}].");
        }
        return new ToolResult(false, "Memory [{$key}] not found to delete.");
    }
}

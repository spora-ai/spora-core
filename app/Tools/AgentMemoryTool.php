<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;

/**
 * Stores and retrieves persistent memories scoped to the current agent.
 * Each agent has its own isolated memory namespace.
 */
#[Tool(
    name: 'memory',
    description: 'Store and retrieve persistent memories scoped to this agent.',
    displayName: 'Agent Memory',
    category: 'productivity',
)]
#[ToolOperation(name: 'list', description: 'List all memories with summaries', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get', description: 'Get a single memory by name', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'save', description: 'Create or update a memory', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'delete', description: 'Delete a memory by name', enabledByDefault: true, requiresApprovalByDefault: false)]
final class AgentMemoryTool extends AbstractMemoryTool
{
    protected function getScope(): string
    {
        return 'agent';
    }
}

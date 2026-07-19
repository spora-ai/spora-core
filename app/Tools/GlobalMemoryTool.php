<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;

/**
 * Stores and retrieves persistent memories shared across all agents.
 * Global memories are visible to every agent but scoped to a user.
 */
#[Tool(
    name: 'global_memory',
    description: 'Store and retrieve persistent memories shared across all agents.',
    displayName: 'Global Memory',
    category: 'productivity',
    icon: 'brain',
)]
#[ToolOperation(name: 'list', description: 'List all memories with summaries', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get', description: 'Get a single memory by name', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'save', description: 'Create or update a memory', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'delete', description: 'Delete a memory by name', enabledByDefault: true, requiresApprovalByDefault: false)]
final class GlobalMemoryTool extends AbstractMemoryTool
{
    protected function getScope(): string
    {
        return 'global';
    }
}

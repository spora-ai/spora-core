<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Models\Agent;
use Spora\Models\Memory;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Abstract base for memory tools that provide list, get, save, and delete operations.
 * Subclasses define the scope (agent or global) via getScope().
 *
 * Extending AbstractTool gives the subclasses (AgentMemoryTool, GlobalMemoryTool)
 * the auto-generated parameter schema for free — they only need their own #[Tool]
 * declaration and an implementation of getScope().
 */
#[ToolParameter(name: 'name', type: 'string', description: 'Unique name for the memory (e.g. "user_preferences", "project_context").', required: false)]
#[ToolParameter(name: 'content', type: 'string', description: 'Memory content in markdown. Required for save action.', required: false)]
#[ToolParameter(name: 'summary', type: 'string', description: 'Brief one-line summary for list view. Auto-derived from content if omitted.', required: false)]
#[ToolParameter(name: 'order', type: 'integer', description: 'Sort order for listing. Defaults to 0.', required: false)]
abstract class AbstractMemoryTool extends AbstractTool
{
    abstract protected function getScope(): string;

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);
        $scope = $this->getScope();

        // Derive userId from agent if not provided (global memories need user context)
        if ($userId === null) {
            $agent = Agent::find($agentId);
            $userId = $agent?->user_id;
        }

        return match ($operation) {
            'list'   => $this->list($scope, $agentId, $userId),
            'get'    => $this->get($arguments, $scope, $agentId, $userId),
            'save'   => $this->save($arguments, $scope, $agentId, $userId),
            'delete' => $this->delete($arguments, $scope, $agentId, $userId),
            default  => new ToolResult(false, 'Invalid action. Must be list, get, save, or delete.'),
        };
    }

    public function describeAction(array $arguments): string
    {
        $op = (string) ($arguments['action'] ?? $this->getOperationName($arguments));
        $name = (string) ($arguments['name'] ?? '');
        return "Memory {$op}: {$name}";
    }

    public function list(string $scope, int $agentId, ?int $userId = null): ToolResult
    {
        if ($scope === 'global') {
            $query = Memory::global();
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
            $memories = $query->orderBy('order')->orderBy('name')->get();
        } else {
            $memories = Memory::forAgent($agentId)->orderBy('order')->orderBy('name')->get();
        }

        if ($memories->isEmpty()) {
            return new ToolResult(true, "No memories found in {$scope} scope.");
        }

        $lines = ["Found {$memories->count()} memory(ies) in {$scope} scope:"];
        foreach ($memories as $m) {
            $summary = $m->summary !== null ? " — {$m->summary}" : '';
            $lines[] = "- [{$m->name}]{$summary}";
        }

        return new ToolResult(true, implode("\n", $lines));
    }

    public function get(array $arguments, string $scope, int $agentId, ?int $userId = null): ToolResult
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return new ToolResult(false, 'Error: name is required for get action.');
        }

        $memory = $this->findMemory($name, $scope, $agentId, $userId);
        if ($memory === null) {
            return new ToolResult(false, "Memory [{$name}] not found in {$scope} scope.");
        }

        $header = "# {$memory->name}";
        if ($memory->summary !== null) {
            $header .= "\n*Summary: {$memory->summary}*";
        }
        $header .= "\n\n";

        return new ToolResult(true, $header . ($memory->content ?? ''));
    }

    public function save(array $arguments, string $scope, int $agentId, ?int $userId = null): ToolResult
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return new ToolResult(false, 'Error: name is required for save action.');
        }

        $content = (string) ($arguments['content'] ?? '');
        $summary = isset($arguments['summary']) ? trim((string) $arguments['summary']) : null;
        $order = isset($arguments['order']) ? (int) $arguments['order'] : 0;

        $query = Memory::where('name', $name);
        $this->applyScopeFilter($query, $scope, $agentId, $userId);

        $memory = $query->first();
        if ($memory !== null) {
            $this->updateMemoryFields($memory, $content, $summary, $order);
            return new ToolResult(true, "Updated memory [{$name}] in {$scope} scope.");
        }

        $summary ??= $this->deriveSummary($content);
        Memory::create($this->buildCreateData($scope, $agentId, $userId, $name, $summary, $content, $order));

        return new ToolResult(true, "Created memory [{$name}] in {$scope} scope.");
    }

    // Helpers extracted from save() to keep its cognitive complexity below SonarQube's
    // php:S3776 threshold. The scope/userId filter and create-data rules are non-trivial
    // branches, and inlining them inflated the method's complexity past the limit.

    private function applyScopeFilter($query, string $scope, int $agentId, ?int $userId): void
    {
        if ($scope === 'global') {
            $query->whereNull('agent_id');
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
        } else {
            $query->where('agent_id', $agentId);
        }
    }

    private function updateMemoryFields(Memory $memory, string $content, ?string $summary, int $order): void
    {
        $memory->content = $content;
        if ($summary !== null) {
            $memory->summary = $summary;
        }
        $memory->order = $order;
        $memory->save();
    }

    private function buildCreateData(string $scope, int $agentId, ?int $userId, string $name, ?string $summary, string $content, int $order): array
    {
        $data = [
            'agent_id' => $scope === 'agent' ? $agentId : null,
            'name'     => $name,
            'summary'  => $summary,
            'content'  => $content,
            'order'    => $order,
        ];

        if (($scope === 'global' && $userId !== null) || $scope === 'agent') {
            $data['user_id'] = $userId;
        }

        return $data;
    }

    private function deriveSummary(string $content): ?string
    {
        return $content !== '' ? mb_substr(strip_tags($content), 0, 200) : null;
    }

    public function delete(array $arguments, string $scope, int $agentId, ?int $userId = null): ToolResult
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return new ToolResult(false, 'Error: name is required for delete action.');
        }

        $query = Memory::where('name', $name);
        if ($scope === 'global') {
            $query->whereNull('agent_id');
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
        } else {
            $query->where('agent_id', $agentId);
        }

        $deleted = $query->delete();
        if ($deleted) {
            return new ToolResult(true, "Deleted memory [{$name}] from {$scope} scope.");
        }

        return new ToolResult(false, "Memory [{$name}] not found in {$scope} scope.");
    }

    private function findMemory(string $name, string $scope, int $agentId, ?int $userId = null): ?Memory
    {
        $query = Memory::where('name', $name);
        if ($scope === 'global') {
            $query->whereNull('agent_id');
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
        } else {
            $query->where('agent_id', $agentId);
        }

        return $query->first();
    }
}

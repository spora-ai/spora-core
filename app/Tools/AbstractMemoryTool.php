<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Models\Memory;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

abstract class AbstractMemoryTool implements ToolInterface
{
    use HasOperations;

    abstract protected function getScope(): string;

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);
        $scope = $this->getScope();

        return match ($operation) {
            'list'   => $this->list($scope, $agentId),
            'get'    => $this->get($arguments, $scope, $agentId),
            'save'   => $this->save($arguments, $scope, $agentId),
            'delete' => $this->delete($arguments, $scope, $agentId),
            default  => new ToolResult(false, 'Invalid action. Must be list, get, save, or delete.'),
        };
    }

    public function describeAction(array $arguments): string
    {
        $op = (string) ($arguments['action'] ?? $this->getOperationName($arguments));
        $name = (string) ($arguments['name'] ?? '');
        return "Memory {$op}: {$name}";
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'description' => 'The action to perform: "list", "get", "save", or "delete".',
                    'enum'        => ['list', 'get', 'save', 'delete'],
                ],
                'name' => [
                    'type'        => 'string',
                    'description' => 'Unique name for the memory (e.g. "user_preferences", "project_context").',
                ],
                'content' => [
                    'type'        => 'string',
                    'description' => 'Memory content in markdown. Required for save action.',
                ],
                'summary' => [
                    'type'        => 'string',
                    'description' => 'Brief one-line summary for list view. Auto-derived from content if omitted.',
                ],
                'order' => [
                    'type'        => 'integer',
                    'description' => 'Sort order for listing. Defaults to 0.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function list(string $scope, int $agentId): ToolResult
    {
        if ($scope === 'global') {
            $memories = Memory::global()->orderBy('order')->orderBy('name')->get();
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

    public function get(array $arguments, string $scope, int $agentId): ToolResult
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return new ToolResult(false, 'Error: name is required for get action.');
        }

        $memory = $this->findMemory($name, $scope, $agentId);
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

    public function save(array $arguments, string $scope, int $agentId): ToolResult
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return new ToolResult(false, 'Error: name is required for save action.');
        }

        $content = (string) ($arguments['content'] ?? '');
        $summary = isset($arguments['summary']) ? trim((string) $arguments['summary']) : null;
        $order = isset($arguments['order']) ? (int) $arguments['order'] : 0;

        if ($summary === null && $content !== '') {
            $summary = mb_substr(strip_tags($content), 0, 200);
        }

        $query = Memory::where('name', $name);
        if ($scope === 'global') {
            $query->whereNull('agent_id');
        } else {
            $query->where('agent_id', $agentId);
        }

        $memory = $query->first();

        if ($memory !== null) {
            $memory->content = $content;
            $memory->summary = $summary;
            $memory->order = $order;
            $memory->save();
            return new ToolResult(true, "Updated memory [{$name}] in {$scope} scope.");
        }

        Memory::create([
            'agent_id' => $scope === 'agent' ? $agentId : null,
            'name'     => $name,
            'summary'  => $summary,
            'content'  => $content,
            'order'    => $order,
        ]);

        return new ToolResult(true, "Created memory [{$name}] in {$scope} scope.");
    }

    public function delete(array $arguments, string $scope, int $agentId): ToolResult
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return new ToolResult(false, 'Error: name is required for delete action.');
        }

        $query = Memory::where('name', $name);
        if ($scope === 'global') {
            $query->whereNull('agent_id');
        } else {
            $query->where('agent_id', $agentId);
        }

        $deleted = $query->delete();
        if ($deleted) {
            return new ToolResult(true, "Deleted memory [{$name}] from {$scope} scope.");
        }

        return new ToolResult(false, "Memory [{$name}] not found in {$scope} scope.");
    }

    private function findMemory(string $name, string $scope, int $agentId): ?Memory
    {
        $query = Memory::where('name', $name);
        if ($scope === 'global') {
            $query->whereNull('agent_id');
        } else {
            $query->where('agent_id', $agentId);
        }

        return $query->first();
    }
}

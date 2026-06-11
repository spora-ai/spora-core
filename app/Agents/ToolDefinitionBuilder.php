<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Support\Collection;
use ReflectionClass;
use Spora\Models\AgentToolOperationOverride;
use Spora\Plugins\PluginLoader;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Schema\OperationSchemaFilter;
use Spora\Tools\Traits\HasOperations;

/**
 * Builds the OpenAI-compatible tool definition list sent to the LLM each tick.
 *
 * Package-private collaborator: constructed and called only by
 * {@see Orchestrator}. The orchestrator injects the tool instances and
 * the config/plugin dependencies.
 */
final class ToolDefinitionBuilder
{
    /**
     * @param  list<object>  $toolInstances
     * @param  callable(array<string, mixed> $llmSettings): string  $buildLlmConfigBlock
     *         Callback into Orchestrator that renders the LLM-facing config block for a tool.
     */
    public function __construct(
        private readonly array $toolInstances,
        private readonly ?ToolConfigService $toolConfigService = null,
        private readonly ?PluginLoader $pluginLoader = null,
        private $buildLlmConfigBlock = null,
    ) {}

    /**
     * @param  list<string>  $enabledClasses
     * @return list<array<string, mixed>>
     */
    public function buildToolDefinitions(array $enabledClasses, int $agentId, ?int $userId = null): array
    {
        $defs = [];
        $overrides = $this->loadOperationOverrides($agentId, $enabledClasses);

        foreach ($this->toolInstances as $instance) {
            $toolClass = get_class($instance);

            if (!in_array($toolClass, $enabledClasses, true)) {
                continue;
            }

            $toolAttr = $this->extractToolAttribute($instance);
            if ($toolAttr === null) {
                continue;
            }

            $def = $this->usesOperationsTrait($toolClass)
                ? $this->buildOperationToolDefinition($instance, $toolClass, $toolAttr, $overrides, $agentId, $userId)
                : $this->buildSimpleToolDefinition($instance, $toolClass, $toolAttr, $agentId, $userId);

            if ($def !== null) {
                $defs[] = $def;
            }
        }

        return $defs;
    }

    private function loadOperationOverrides(int $agentId, array $enabledClasses): Collection
    {
        return AgentToolOperationOverride::where('agent_id', $agentId)
            ->whereIn('tool_class', $enabledClasses)
            ->get()
            ->keyBy(fn($row) => $row->tool_class . '::' . $row->operation);
    }

    private function extractToolAttribute(object $instance): ?Tool
    {
        $ref = new ReflectionClass($instance);
        $attrs = $ref->getAttributes(Tool::class);

        if ($attrs === []) {
            return null;
        }

        return $attrs[0]->newInstance();
    }

    private function usesOperationsTrait(string $toolClass): bool
    {
        return in_array(HasOperations::class, class_uses_recursive($toolClass), true);
    }

    private function buildOperationToolDefinition(
        object $instance,
        string $toolClass,
        Tool $toolAttr,
        Collection $overrides,
        int $agentId,
        ?int $userId,
    ): ?array {
        $allowedOps = $this->resolveAllowedOperations($instance, $toolClass, $overrides);
        if ($allowedOps === []) {
            return null;
        }

        $schema = $instance->getParametersSchema();
        $operations = $instance->getOperations();
        $discriminatorKey = $operations[0]->discriminatorKey ?? 'action';
        $filteredSchema = OperationSchemaFilter::filter($schema, $allowedOps, $discriminatorKey);

        return [
            'type'     => 'function',
            'function' => [
                'name'        => $this->qualifiedToolName($toolClass, $toolAttr->name),
                'description' => $toolAttr->description . $this->buildConfigBlockFor($toolClass, $agentId, $userId),
                'parameters'  => $filteredSchema,
            ],
        ];
    }

    private function buildSimpleToolDefinition(
        object $instance,
        string $toolClass,
        Tool $toolAttr,
        int $agentId,
        ?int $userId,
    ): array {
        $schema = $instance->getParametersSchema();

        if (isset($schema['properties']) && $schema['properties'] === []) {
            $schema['properties'] = (object) [];
        }

        return [
            'type'     => 'function',
            'function' => [
                'name'        => $this->qualifiedToolName($toolClass, $toolAttr->name),
                'description' => $toolAttr->description . $this->buildConfigBlockFor($toolClass, $agentId, $userId),
                'parameters'  => $schema,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveAllowedOperations(object $instance, string $toolClass, Collection $overrides): array
    {
        $allowedOps = [];

        foreach ($instance->getOperations() as $op) {
            $key = $toolClass . '::' . $op->name;
            $row = $overrides->get($key);

            if ($row !== null) {
                if ($row->enabled === 0) {
                    continue;
                }
                if ($row->enabled === 1) {
                    $allowedOps[] = $op->name;
                    continue;
                }
            }

            if ($op->enabledByDefault) {
                $allowedOps[] = $op->name;
            }
        }

        return $allowedOps;
    }

    private function buildConfigBlockFor(string $toolClass, int $agentId, ?int $userId): string
    {
        $llmSettings = $this->toolConfigService !== null
            ? $this->toolConfigService->getLlmToolSettings($toolClass, $agentId, $userId)
            : [];

        if ($this->buildLlmConfigBlock !== null) {
            return ($this->buildLlmConfigBlock)($llmSettings);
        }

        return '';
    }

    public function qualifiedToolName(string $toolClass, string $plainName): string
    {
        if ($this->pluginLoader !== null) {
            foreach ($this->pluginLoader->getPlugins() as $slug => $plugin) {
                if (in_array($toolClass, $plugin->tools(), true)) {
                    return "{$slug}:{$plainName}";
                }
            }
        }

        return $plainName;
    }
}

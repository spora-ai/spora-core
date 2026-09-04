<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Spora\Models\AgentToolOperationOverride;
use Spora\Plugins\PluginLoader;
use Spora\Services\PrincipalContext;
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
     * Per-process dedup set for the "missing #[ToolOperation]" loud error:
     * a multi-tick task would otherwise log once per tick per broken tool,
     * drowning the alert channel. The set is rebuilt per request, which is
     * the natural unit for the orchestrator anyway.
     *
     * @var array<string, true>
     */
    private array $loggedMissingOperations = [];

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
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param  list<string>  $enabledClasses
     * @return list<array<string, mixed>>
     */
    public function buildToolDefinitions(array $enabledClasses, int $agentId, ?PrincipalContext $context = null): array
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
                ? $this->buildOperationToolDefinition($instance, $toolClass, $toolAttr, $overrides, $agentId, $context)
                : $this->buildSimpleToolDefinition($instance, $toolClass, $toolAttr, $agentId, $context);

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
        ?PrincipalContext $context,
    ): ?array {
        $allowedOps = $this->resolveAllowedOperations($instance, $toolClass, $overrides);
        if ($allowedOps === []) {
            // The tool declares no #[ToolOperation] attributes (or every
            // declared op is disabled by overrides with no fallback), so
            // the LLM-facing schema would silently drop it. The agent
            // would then complain "the tool isn't in my callable schema"
            // with no breadcrumb — log loudly on the first hit per
            // process so the broken tool class surfaces in `storage/spora.log`
            // and any operator-configured alerting. The dedup set is
            // per-request (see property docblock); the next request repeats
            // the log so a fresh deploy with a broken plugin still alerts.
            if (!isset($this->loggedMissingOperations[$toolClass])) {
                $this->loggedMissingOperations[$toolClass] = true;
                $this->logger?->error(
                    'Tool {tool_class} has no callable #[ToolOperation] attributes and will be missing from the LLM-facing schema. Declare at least one #[ToolOperation(name, enabledByDefault: true)] on the tool class.',
                    [
                        'tool_class' => $toolClass,
                        'agent_id'   => $agentId,
                    ],
                );
            }
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
                'description' => $toolAttr->description . $this->buildConfigBlockFor($toolClass, $agentId, $context),
                'parameters'  => $filteredSchema,
            ],
        ];
    }

    private function buildSimpleToolDefinition(
        object $instance,
        string $toolClass,
        Tool $toolAttr,
        int $agentId,
        ?PrincipalContext $context,
    ): array {
        $schema = $instance->getParametersSchema();

        if (isset($schema['properties']) && $schema['properties'] === []) {
            $schema['properties'] = (object) [];
        }

        return [
            'type'     => 'function',
            'function' => [
                'name'        => $this->qualifiedToolName($toolClass, $toolAttr->name),
                'description' => $toolAttr->description . $this->buildConfigBlockFor($toolClass, $agentId, $context),
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

    private function buildConfigBlockFor(string $toolClass, int $agentId, ?PrincipalContext $context): string
    {
        $llmSettings = $this->toolConfigService !== null
            ? $this->toolConfigService->getLlmToolSettings($toolClass, $agentId, $context?->ownerUserId, $context)
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

<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Plugins\PluginLoader;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Services\ToolIconResolver;
use Spora\Tools\ToolSchemaPresenter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Presents the available-tool catalog to the LLM (`get_available_tools`).
 *
 * Emits two shapes:
 *   - `$enriched` (legacy operator-side) becomes the persisted
 *     `tool_calls.result_data` and keeps the operator UI's
 *     `tool_name`/`category`/`icon` expectations intact.
 *   - `$agentFacing` (slim v2) drops those fields because they're
 *     redundant for an LLM that already has `tool_class` and wants to
 *     call `configure_tools` next.
 */
final class CatalogPresenter
{
    public function __construct(
        private readonly AgentServiceInterface $agentService,
        private readonly AgentToolSettingsServiceInterface $toolSettings,
        private readonly ?PluginLoader $pluginLoader = null,
        private readonly ?ToolIconResolver $iconResolver = null,
    ) {}

    /**
     * `get_available_tools` executor. `userId` is nullable as a "minimal
     * boot" fallback — the orchestrator normally fills it from the
     * calling Agent's row.
     */
    public function present(int $agentId, ?int $userId): ToolResult
    {
        $agent = $this->agentService->getAgentByAgentId($agentId);
        if ($agent === null) {
            return ToolResult::fail(self::notFoundMessage());
        }

        $resolvedUserId = $userId ?? (int) $agent->user_id;

        $rows = $this->toolSettings->getAllToolsStatus($agentId, $resolvedUserId) ?? [];
        $operationsByClass = self::indexOperationsByToolClass(
            $this->toolSettings->getToolsOperations($agentId, $resolvedUserId) ?? [],
        );

        $enriched = [];
        foreach ($rows as $row) {
            $toolClass = (string) $row['tool_class'];
            $summary = ToolSchemaPresenter::summarize(
                $toolClass,
                $this->iconResolver?->resolve($toolClass),
            );
            $enriched[] = [
                'tool_class'          => $toolClass,
                'tool_name'           => $summary['tool_name'],
                'display_name'        => $summary['display_name'],
                'description'         => $summary['description'],
                'category'            => $summary['category'],
                'icon'                => $summary['icon'],
                'is_enabled'          => (bool) $row['is_enabled'],
                'needs_configuration' => $row['can_enable'] === false,
                'missing_required'    => $row['missing_required'],
                'operations'          => $summary['operations'],
            ];
        }

        $agentFacing = $this->buildAgentFacingToolRows($enriched, $operationsByClass);
        $content = json_encode(
            $agentFacing,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return ToolResult::ok($content, $enriched);
    }

    /**
     * v2 dropped `tool_name` / `call_name` / `category` / `icon` /
     * `source` — each is redundant for the LLM (overlaps with
     * `tool_class`, operator-UI concerns, etc.).
     *
     * `missing_required` lists only the required setting keys; no
     * effective values are exposed to avoid leaking credentials.
     * Full parameter schemas are deliberately omitted — the LLM can
     * call `configure_tools` with `operations: [{name, …}]` entries
     * without needing the schema.
     *
     * @param  list<array<string, mixed>> $rows Per-agent status rows (already enriched by the caller).
     * @param  array<string, list<array{operation: string, effective_enabled: bool, effective_requires_approval: bool}>> $operationsByClass
     *         Effective per-op state, keyed by tool_class.
     * @return array<string, mixed>
     */
    private function buildAgentFacingToolRows(array $rows, array $operationsByClass): array
    {
        $tools = [];
        foreach ($rows as $row) {
            $toolClass = (string) $row['tool_class'];
            $pluginSlug = $this->pluginLoader?->getSlugForToolClass($toolClass);
            $toolOperations = self::buildAgentFacingOperations(
                (array) ($row['operations'] ?? []),
                $operationsByClass[$toolClass] ?? [],
            );
            $tools[] = [
                'tool_class'       => $toolClass,
                'display_name'     => (string) $row['display_name'],
                'description'      => (string) ($row['description'] ?? ''),
                'plugin_slug'      => $pluginSlug,
                'enabled'          => (bool) $row['is_enabled'],
                'ready_to_enable'  => $row['needs_configuration'] === false,
                'missing_required' => (array) $row['missing_required'],
                'operations'       => $toolOperations,
            ];
        }

        return [
            'version' => 2,
            'count'   => count($tools),
            'tools'   => $tools,
        ];
    }

    /**
     * @param list<array{name: string, description: string, enabledByDefault: bool, requiresApprovalByDefault: bool, discriminatorKey: string}> $declaredOperations
     * @param list<array{operation: string, effective_enabled: bool, effective_requires_approval: bool}> $effectiveOperations
     * @return list<array{name: string, description: string, enabled: bool, requires_approval: bool}>
     */
    private static function buildAgentFacingOperations(
        array $declaredOperations,
        array $effectiveOperations,
    ): array {
        if ($declaredOperations === []) {
            return [];
        }
        $effectiveByName = [];
        foreach ($effectiveOperations as $op) {
            $effectiveByName[(string) $op['operation']] = $op;
        }
        $out = [];
        foreach ($declaredOperations as $op) {
            $name = (string) $op['name'];
            $effective = $effectiveByName[$name] ?? null;
            $out[] = [
                'name'              => $name,
                'description'       => (string) $op['description'],
                'enabled'           => $effective === null
                    ? (bool) $op['enabledByDefault']
                    : (bool) $effective['effective_enabled'],
                'requires_approval' => $effective === null
                    ? (bool) $op['requiresApprovalByDefault']
                    : (bool) $effective['effective_requires_approval'],
            ];
        }
        return $out;
    }

    /**
     * @param  list<array{tool_class: string, operation: string, effective_enabled: bool, effective_requires_approval: bool}> $operations
     * @return array<string, list<array{tool_class: string, operation: string, effective_enabled: bool, effective_requires_approval: bool}>>
     */
    private static function indexOperationsByToolClass(array $operations): array
    {
        $indexed = [];
        foreach ($operations as $op) {
            $indexed[(string) $op['tool_class']][] = $op;
        }
        return $indexed;
    }

    private static function notFoundMessage(): string
    {
        return 'Agent not found.';
    }
}

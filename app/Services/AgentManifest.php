<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;
use Spora\Tools\ToolSchemaPresenter;

/**
 * Canonical agent wire format shared by every AgentTool read/write operation
 * (`create_agent` output, `read_agent` output, `configure_tools` output,
 * `write_agent_configuration` output, and the public
 * `GET /api/v1/agents/{id}` JSON-API response).
 *
 * The shape is the canonical reference for the agent manifest:
 *
 * ```
 * {
 *   // Base config — the agent's identity + behaviour record.
 *   "agent_id":   <int>,
 *   "name":       <string>,
 *   "description": <string|null>,
 *   "system_prompt": <string|null>,
 *   "template_id": <string|null>,  // optional, only set if known
 *   "version":    <string|null>,   // optional, only set if known
 *   "max_steps":  <int>,
 *   "allow_followup": <bool>,
 *   "retry_after_minutes": <int>,
 *   "max_retries": <int>,
 *   "is_pinned":   <bool>,
 *   "is_archived": <bool>,
 *   "is_favorite": <bool>,
 *
 *   // Tool config — separate sub-document. Empty when the agent has no tools.
 *   "tools": [
 *     {
 *       "tool_class":   <FQCN>,
 *       "display_name": <string>,
 *       "description":  <string>,
 *       "icon":         <string|null>,
 *       "enabled":      <bool>,
 *       "operations": [
 *         { "name": <string>, "enabled": <bool>, "requires_approval": <bool> }
 *       ]
 *     }
 *   ],
 *
 *   // Diagnostics — surfaced on read paths, ignored on write/create inputs.
 *   "missing_required": <list<string>>,   // tool-class keys missing config
 *   "warnings":         <list<string>>     // human-readable caveats
 * }
 * ```
 *
 * Single source of truth so the LLM-facing `read_agent` Markdown, the
 * `configure_tools` confirmation, and the public REST response can not
 * drift. Outputs are deterministic so test fixtures hash cleanly.
 */
final class AgentManifest
{
    /**
     * @param AgentToolSettingsServiceInterface $toolSettings Per-agent state reader.
     * @param ?ToolIconResolver $iconResolver Optional 3-layer icon chain.
     */
    public function __construct(
        private readonly AgentToolSettingsServiceInterface $toolSettings,
        private readonly ?ToolIconResolver $iconResolver = null,
    ) {}

    /**
     * Build the canonical manifest for an agent. The agent does not need
     * to have its `agentTools` relation eagerly loaded — this method
     * queries the per-agent status and operation state explicitly so the
     * caller can hand in a freshly-loaded `Agent` from anywhere.
     *
     * @return array<string, mixed>
     */
    public function toArray(Agent $agent): array
    {
        $userId = (int) $agent->user_id;
        $agentId = (int) $agent->id;

        $rows = $this->toolSettings->getAllToolsStatus($agentId, $userId) ?? [];
        $operationsByTool = $this->indexOperationsByToolClass(
            $this->toolSettings->getToolsOperations($agentId, $userId) ?? [],
        );

        $tools = [];
        $missingRequired = [];
        foreach ($rows as $row) {
            $toolClass = (string) $row['tool_class'];
            $summary = ToolSchemaPresenter::summarize(
                $toolClass,
                $this->iconResolver?->resolve($toolClass),
            );
            // Declared ops come from the tool's own `#[ToolOperation]`
            // attribute set, not from `getAllToolsStatus` (which carries
            // enablement flags only). Effective ops come from
            // `getToolsOperations`, which folds per-agent overrides into
            // the per-class declared defaults via AgentToolOperationsResolver.
            $declaredOperations = $summary['operations'];
            $effective = $operationsByTool[$toolClass] ?? [];

            $enabled = (bool) $row['is_enabled'];
            // The per-tool missing_required list is a flat string list of
            // required setting keys (no values) — emits only for enabled
            // tools that cannot actually fire until config is supplied.
            if ($enabled && $row['missing_required'] !== []) {
                foreach ($row['missing_required'] as $key) {
                    $missingRequired[] = "{$toolClass}:{$key}";
                }
            }

            $operations = $this->mergeOperations($declaredOperations, $effective);

            $tools[] = [
                'tool_class'   => $toolClass,
                'display_name' => (string) $summary['display_name'],
                'description'  => (string) $summary['description'],
                'icon'         => $summary['icon'] ?? null,
                'enabled'      => $enabled,
                'operations'   => $operations,
            ];
        }

        return [
            'agent_id'            => $agentId,
            'name'                => $agent->name,
            'description'         => $agent->description,
            'system_prompt'       => $agent->system_prompt,
            'template_id'         => null,
            'version'             => null,
            'max_steps'           => (int) $agent->max_steps,
            'allow_followup'      => (bool) $agent->allow_followup,
            'retry_after_minutes' => (int) ($agent->retry_after_minutes ?? 0),
            'max_retries'         => (int) ($agent->max_retries ?? 0),
            'is_pinned'           => (bool) ($agent->is_pinned ?? false),
            'is_archived'         => (bool) ($agent->is_archived ?? false),
            'is_favorite'         => (bool) ($agent->is_favorite ?? false),
            'tools'               => $tools,
            'missing_required'    => $missingRequired,
            'warnings'            => [],
        ];
    }

    /**
     * Merge declared per-operation metadata with the effective (override-aware)
     * enabled / requires_approval state. When no override exists, fall back
     * to the declared default. Always emits a row in declared-operation order
     * so callers can compare manifests without sort-induced diff churn.
     *
     * @param list<array{name: string, description: string, enabledByDefault: bool, requiresApprovalByDefault: bool, discriminatorKey: string}> $declared
     * @param list<array{operation: string, effective_enabled: bool, effective_requires_approval: bool}> $effective
     * @return list<array{name: string, enabled: bool, requires_approval: bool}>
     */
    private function mergeOperations(array $declared, array $effective): array
    {
        if ($declared === []) {
            return [];
        }
        $effectiveByName = [];
        foreach ($effective as $op) {
            $effectiveByName[(string) $op['operation']] = $op;
        }
        $out = [];
        foreach ($declared as $op) {
            $name = (string) $op['name'];
            $row = $effectiveByName[$name] ?? null;
            $out[] = [
                'name'              => $name,
                'enabled'           => $row === null
                    ? (bool) $op['enabledByDefault']
                    : (bool) $row['effective_enabled'],
                'requires_approval' => $row === null
                    ? (bool) $op['requiresApprovalByDefault']
                    : (bool) $row['effective_requires_approval'],
            ];
        }
        return $out;
    }

    /**
     * @param list<array{tool_class: string, operation: string, effective_enabled: bool, effective_requires_approval: bool}> $rows
     * @return array<string, list<array{operation: string, effective_enabled: bool, effective_requires_approval: bool}>>
     */
    private function indexOperationsByToolClass(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $op) {
            $indexed[(string) $op['tool_class']][] = $op;
        }
        return $indexed;
    }
}

<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;
use Spora\Tools\ToolSchemaPresenter;

/**
 * Canonical agent wire format shared by every AgentTool read/write operation
 * that hands an Agent row back to the LLM — `create_agent` output,
 * `read_agent` output, `configure_tools` output, and `update_agent` output.
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
 *   // Each entry is slim (FQCN + icon + enablement + per-op state) on
 *   // purpose: `display_name` and `description` are useful when an agent
 *   // browses tools, but balloon the LLM context for `read_agent`,
 *   // `update_agent`, and `configure_tools` responses. Operators who
 *   // want descriptive metadata call `get_available_tools` instead.
 *   "tools": [
 *     {
 *       "tool_class": <FQCN>,
 *       "icon":       <string|null>,
 *       "enabled":    <bool>,
 *       "operations": [
 *         { "name": <string>, "enabled": <bool>, "requires_approval": <bool> }
 *       ]
 *     }
 *   ],
 *
 *   // Diagnostics — surfaced on read paths, ignored on write/create inputs.
 *   "missing_required": <list<string>>,   // tool-class keys missing config
 *   "overrides":        <list<...>>,      // per-op override rows (audit trail)
 *   "warnings":         <list<string>>     // human-readable caveats
 * }
 * ```
 *
 * The `overrides` list makes `auto_approve: true|false` and per-op
 * `enabled` overrides auditable: each row carries the FQCN + operation +
 * the explicit override value (either nullable = "default was kept" or a
 * 0|1 bool = "override was set"). Operators who want to confirm a
 * `configure_tools` patch landed the way they asked can diff the
 * response's `overrides[]` against what they sent.
 *
 * Single source of truth so the LLM-facing `read_agent` Markdown and
 * the `configure_tools` confirmation can not drift. Outputs are
 * deterministic so test fixtures hash cleanly.
 *
 * Note: the REST `GET /api/v1/agents/{id}` endpoint renders via
 * `AgentResource::toArray()` (not this class). This service backs only
 * the LLM-facing AgentTool operations — see {@see \Spora\Tools\AgentTool}.
 */
final class AgentManifest
{
    /**
     * @param AgentToolSettingsServiceInterface $toolSettings Per-agent state reader.
     * @param ?ToolIconResolver $iconResolver Optional 3-layer icon chain.
     * @param ?PrincipalResolver $principalResolver Optional resolver
     *        for translating the agent's principal back to a user id
     *        when computing "who owns this agent?" for downstream
     *        visibility checks. Defaults to a freshly-constructed
     *        resolver so callers without DI wiring keep working.
     */
    public function __construct(
        private readonly AgentToolSettingsServiceInterface $toolSettings,
        private readonly ?ToolIconResolver $iconResolver = null,
        private readonly ?PrincipalResolver $principalResolver = null,
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
        // Migrated from `$agent->user_id` to PrincipalResolver: the
        // owning principal can be a user-principal or a group-principal.
        // For a group, the principal's first user-principal is the
        // canonical "owner" we feed to downstream ownership checks
        // (the controllers and tool-resolvers still key on userId, so
        // we resolve through the principal axis rather than reading the
        // agents row directly).
        $resolver = $this->principalResolver ?? new PrincipalResolver();
        $userId   = $resolver->ownerUserId((int) $agent->principal_id) ?? 0;
        $agentId  = (int) $agent->id;

        $rows = $this->toolSettings->getAllToolsStatus($agentId, $userId) ?? [];
        $operationsByTool = self::indexOperationsByToolClass(
            $this->toolSettings->getToolsOperations($agentId, $userId) ?? [],
        );

        $tools = [];
        $missingRequired = [];
        $overrides = [];
        foreach ($rows as $row) {
            $rowPayload = $this->buildManifestRow($row, $operationsByTool);
            $tools[]           = $rowPayload['tool_entry'];
            $missingRequired   = array_merge($missingRequired, $rowPayload['missing_required']);
            $overrides         = array_merge($overrides, $rowPayload['overrides']);
        }

        return [
            'agent_id'            => $agentId,
            'name'                => $agent->name,
            'description'         => $agent->description,
            'system_prompt'       => $agent->system_prompt,
            'notes'               => $agent->notes,
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
            'overrides'           => $overrides,
            'warnings'            => [],
        ];
    }

    /**
     * Build the per-tool manifest row + missing-required + override
     * audit-trail for a single status row.
     *
     * Declared ops come from the tool's own `#[ToolOperation]`
     * attribute set, not from `getAllToolsStatus` (which carries
     * enablement flags only). Effective ops come from
     * `getToolsOperations`, which folds per-agent overrides into the
     * per-class declared defaults via AgentToolOperationsResolver.
     *
     * @param  array<string, mixed> $row
     * @param  array<string, list<array{tool_class: string, operation: string, enabled: int|null, default_requires_approval: int|null, effective_enabled: bool, effective_requires_approval: bool}>> $operationsByTool
     * @return array{tool_entry: array<string, mixed>, missing_required: list<string>, overrides: list<array<string, mixed>>}
     */
    private function buildManifestRow(array $row, array $operationsByTool): array
    {
        $toolClass = (string) $row['tool_class'];
        $summary = ToolSchemaPresenter::summarize(
            $toolClass,
            $this->iconResolver?->resolve($toolClass),
        );

        $enabled       = (bool) $row['is_enabled'];
        $effective     = $operationsByTool[$toolClass] ?? [];
        $operations    = self::mergeOperations($summary['operations'], $effective);
        $missingReq    = self::missingRequiredFor($toolClass, $enabled, (array) $row['missing_required']);
        $overrideAudit = self::overrideAuditFor($effective);

        return [
            'tool_entry'       => [
                'tool_class' => $toolClass,
                'icon'       => $summary['icon'] ?? null,
                'enabled'    => $enabled,
                'operations' => $operations,
            ],
            'missing_required' => $missingReq,
            'overrides'        => $overrideAudit,
        ];
    }

    /**
     * Flat-string list of required-setting keys (no values) — emits
     * only for enabled tools that cannot actually fire until config
     * is supplied.
     *
     * @param  list<string> $missingRequiredKeys
     * @return list<string>
     */
    private static function missingRequiredFor(string $toolClass, bool $enabled, array $missingRequiredKeys): array
    {
        if (!$enabled || $missingRequiredKeys === []) {
            return [];
        }
        $entries = [];
        foreach ($missingRequiredKeys as $key) {
            $entries[] = "{$toolClass}:{$key}";
        }
        return $entries;
    }

    /**
     * Audit trail for per-operation overrides. `enabled` and
     * `default_requires_approval` are nullable on the resolver's
     * row — null means "default kept", non-null means "this override
     * was set on this agent". Operators who want to verify their
     * `configure_tools(tools[i].operations[j]: {auto_approve: true})`
     * landed the way they asked look at this list, not at the
     * `tools[i].operations[j]` block's `requires_approval` effective
     * value (which folds default + override together and loses the
     * override source).
     *
     * @param  list<array{tool_class: string, operation: string, enabled: int|null, default_requires_approval: int|null, effective_enabled: bool, effective_requires_approval: bool}> $effective
     * @return list<array<string, mixed>>
     */
    private static function overrideAuditFor(array $effective): array
    {
        $out = [];
        foreach ($effective as $row) {
            $auditEnabled  = $row['enabled']                   ?? null;
            $auditApproval = $row['default_requires_approval'] ?? null;
            if ($auditEnabled === null && $auditApproval === null) {
                continue;
            }
            $out[] = [
                'tool_class'                => (string) $row['tool_class'],
                'operation'                 => (string) $row['operation'],
                'enabled'                   => $auditEnabled === null ? null : (bool) $auditEnabled,
                'default_requires_approval' => $auditApproval === null ? null : (bool) $auditApproval,
            ];
        }
        return $out;
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
    private static function mergeOperations(array $declared, array $effective): array
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
     * Index the per-operation rows by their tool_class. Each row also
     * carries the explicit override columns (`enabled`,
     * `default_requires_approval`) — typed as `int|null` because
     * the resolver inserts null when the operator didn't override the
     * corresponding default. `mergeOperations` reads `operation`,
     * `effective_enabled`, `effective_requires_approval`; the override
     * fields are read separately by `toArray()` when emitting
     * `overrides[]` for the audit trail.
     *
     * @param list<array{tool_class: string, operation: string, enabled: int|null, default_requires_approval: int|null, effective_enabled: bool, effective_requires_approval: bool}> $rows
     * @return array<string, list<array{tool_class: string, operation: string, enabled: int|null, default_requires_approval: int|null, effective_enabled: bool, effective_requires_approval: bool}>>
     */
    private static function indexOperationsByToolClass(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $op) {
            $indexed[(string) $op['tool_class']][] = $op;
        }
        return $indexed;
    }
}

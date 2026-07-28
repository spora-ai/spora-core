<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\AgentTemplates\ValidationResult;
use Spora\Models\Agent;
use Spora\Plugins\PluginLoader;
use Spora\Services\AgentResource;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Services\Text\Utf8Sanitizer;
use Spora\Services\ToolIconResolver;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Lets the agent inspect and modify its own configuration, manage its
 * operator-facing notes, discover the tools it could enable, and create
 * new agents on behalf of the current user.
 *
 * All operations except `read_agent` scope to the calling agent
 * (`$agentId` from `Orchestrator::safeExecute()`); the tool never accepts
 * an `agent_id` argument on its mutating operations, so an agent cannot
 * rewrite a sibling. `read_agent` is the explicit exception — it accepts
 * `agent_id` (or `template_id`, if present) so the LLM can verify a
 * freshly-imported agent's state after `create_agent` /
 * `configure_tools`. Ownership is still scoped to the authenticated user.
 *
 * `create_agent` reuses {@see AgentTemplateImporter::importPayload()} so
 * the LLM path and the operator upload endpoint share validation,
 * warnings, and tool-activation semantics. The LLM-facing flow runs the
 * two-phase create → configure_tools pattern (see
 * skills/agent-creation/SKILL.md) so a single call does not have to make
 * N nested decisions about per-tool/per-operation overrides.
 *
 * Tool activation on the calling agent is exposed as `configure_tools`.
 * `get_available_tools` returns rich per-tool metadata so the agent can
 * either (a) plan a toolset to apply via `configure_tools`, or (b) spawn
 * a sub-agent with a chosen toolset via `create_agent`.
 *
 * Operations:
 *   - read_agent_configuration  (enabled, no approval)
 *   - write_agent_configuration (disabled, requires approval)
 *   - read_notes                (enabled, no approval)
 *   - write_notes               (enabled, no approval; append/prepend only)
 *   - write_notes_overwrite     (disabled, requires approval — destructive)
 *   - get_available_tools       (disabled, no approval)
 *   - create_agent              (disabled, requires approval)
 *   - configure_tools           (disabled, requires approval)
 *   - read_agent                (disabled, no approval)
 */
#[Tool(
    name: 'agent',
    description: 'Inspect or modify this agent: read/write its configuration, manage its '
               . 'operator-facing notes, list available tools (with details such as '
               . 'description, source plugin, per-operation enablement and approval state, '
               . 'and any missing required configuration), and create new agents. '
               . 'All operations scope to the calling agent — the tool never accepts an '
               . 'agent_id argument.',
    displayName: 'Agent',
    category: 'agent',
    icon: 'bot',
)]
#[ToolOperation(
    name: 'read_agent_configuration',
    description: 'Read the full configuration of the calling agent (name, description, '
               . 'system prompt, notes, max steps, continuation, retry, pin/archive/favorite, '
               . 'enabled tools). ... Read full docs: skills/agent-creation/SKILL.md.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'write_agent_configuration',
    description: 'Update editable fields on this agent (name, description, system_prompt, max_steps, '
               . 'allow_followup, retry_after_minutes, max_retries, is_pinned, is_archived, is_favorite). '
               . 'Notes MUST go through write_notes / write_notes_overwrite — they are stripped from '
               . 'this patch. Unknown keys (llm_driver_config_id, anything else outside the allowlist) '
               . 'are silently dropped at the database layer; call read_agent_configuration afterwards '
               . 'to confirm the change took effect. For full allowlist and common pitfalls, read the '
               . 'agent-creation skill (skill action: read, name: agent-creation, filename: SKILL.md).',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'read_notes',
    description: 'Read the markdown notes attached to the calling agent.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'write_notes',
    description: 'Append (default) or prepend markdown notes on the calling agent. '
               . 'Segments are joined with a blank line. The destructive `overwrite` '
               . 'mode is a separate `write_notes_overwrite` operation that requires '
               . 'operator approval.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'write_notes_overwrite',
    description: 'Replace the agent\'s markdown notes wholesale. Destructive — wipes any '
               . 'operator-curated notes. Disabled by default and requires explicit '
               . 'operator approval per call so an LLM cannot wipe notes without '
               . 'operator sign-off.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'get_available_tools',
    description: 'List every registered tool with a compact, versioned JSON payload '
               . '(version 1) covering tool_name, call_name, description, source, '
               . 'current enablement, missing required configuration, and per-operation '
               . 'enabled/requires_approval state. Tools that need configuration to '
               . 'become activatable are flagged via `ready_to_enable: false`. '
               . 'Use this to plan a sub-agent via `create_agent`; tool activation on '
               . 'the calling agent itself is operator-only and not exposed here. When '
               . 'planning a sub-agent, also read the agent-creation skill (skill action: '
               . 'read, name: agent-creation).',
    enabledByDefault: false,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'create_agent',
    description: 'Create a new agent from an Agent Template-shaped payload (id, name, version, agent{}, '
               . 'required_plugins[]). The schema is strict — payloads that put `name` inside agent{}, '
               . 'send `operations` as strings instead of `[{name: ...}]` objects, or use a short '
               . 'version like "1.0" instead of semver "1.0.0" will fail validation. Strongly '
               . 'recommend reading the agent-creation skill first (skill action: read, name: '
               . 'agent-creation, filename: SKILL.md) so you do not waste approval cycles on a '
               . 'malformed payload. Note: the LLM-facing flow does NOT pass a `tools[]` block here — '
               . 'after the agent row is created, call `configure_tools` to apply a toolset, then '
               . '`read_agent` to verify what actually committed. Operator-upload templates (file '
               . 'upload endpoint) keep the nested `tools[]` shape and apply it atomically.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'configure_tools',
    description: 'Enable or disable tools and per-operation overrides on the calling agent. '
               . 'Takes a `tools` list with `{ tool_class, enabled, operations: [{name, enabled?, auto_approve?}] }`. '
               . 'A tool with `enabled: false` removes it from the agent. '
               . 'Omit `operations` to inherit defaults; pass `[{name:"now"}]` to enable one, '
               . '`[{name:"now", enabled:false}]` to disable one, '
               . '`[{name:"now", auto_approve:true}]` to enable auto-approve. '
               . 'Returns the updated per-tool/per-operation state. Use `read_agent` afterwards to verify.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'read_agent',
    description: 'Read the full configuration of a specific agent — `id`, `name`, `description`, '
               . '`system_prompt`, enabled tools, and per-operation `enabled` / `requires_approval` state. '
               . 'Use this to verify what was actually committed by `create_agent` and `configure_tools` — '
               . 'silent drops do occur, so the read-back is the source of truth. '
               . 'Identify the target by `template_id` (the same string used in `create_agent`\'s `id`) '
               . 'or by `agent_id` (the numeric primary key returned by `create_agent`). '
               . 'This is the only AgentTool operation that takes an agent identifier.',
    enabledByDefault: false,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(
    name: 'agent',
    type: 'object',
    description: 'ONLY for write_agent_configuration: a partial agent with the fields to '
              . 'update. Allowed keys: name, description, system_prompt, max_steps, '
              . 'allow_followup, retry_after_minutes, max_retries, is_pinned, is_archived, '
              . 'is_favorite. `notes` is intentionally not accepted here — use write_notes. '
              . 'Ignored by every other operation; omit this key entirely when calling '
              . 'read_agent_configuration, read_notes, write_notes, write_notes_overwrite, '
              . 'get_available_tools, or create_agent.',
    required: ['write_agent_configuration'],
)]
#[ToolParameter(
    name: 'content',
    type: 'string',
    description: 'ONLY for write_notes and write_notes_overwrite: the markdown segment to '
              . 'write. Combined with `mode` against the current notes. Ignored by every '
              . 'other operation; omit this key entirely when calling read_agent_configuration, '
              . 'read_notes, write_agent_configuration, get_available_tools, or create_agent.',
    required: ['write_notes', 'write_notes_overwrite'],
)]
#[ToolParameter(
    name: 'mode',
    type: 'string',
    description: 'ONLY for write_notes: how to combine `content` with the existing notes. '
              . '`append` (default, safe) keeps existing notes and adds new content; '
              . '`prepend` puts new content before. Wholesale replacement is a separate '
              . '`write_notes_overwrite` operation (requires operator approval). Ignored by '
              . 'every other operation.',
    required: false,
    enum: ['append', 'prepend'],
    default: 'append',
)]
#[ToolParameter(
    name: 'payload',
    type: 'object',
    description: 'ONLY for create_agent: an Agent Template payload — same shape as the '
              . 'operator upload endpoint. The schema is strict and the validation messages '
              . 'on failure are unforgiving — see the agent-creation skill '
              . '(skill action: read, name: agent-creation, filename: SKILL.md) for the '
              . 'exact shape. Required plugins are NOT auto-installed; missing plugins '
              . 'produce warnings rather than aborting the import. Ignored by every other '
              . 'operation; omit this key entirely when calling read_agent_configuration, '
              . 'read_notes, write_notes, write_notes_overwrite, write_agent_configuration, '
              . 'configure_tools, read_agent, or get_available_tools.',
    required: ['create_agent'],
)]
#[ToolParameter(
    name: 'tools',
    type: 'array',
    description: 'ONLY for configure_tools: a list of `{ tool_class, enabled, operations: [...] }` entries. '
              . 'Each operation entry may set `enabled` (default true) and `auto_approve` (default false). '
              . 'A tool with `enabled: false` removes it from the agent. '
              . 'Ignored by every other operation; omit this key entirely when calling '
              . 'read_agent_configuration, read_notes, write_notes, write_notes_overwrite, '
              . 'write_agent_configuration, get_available_tools, create_agent, or read_agent.',
    required: ['configure_tools'],
)]
#[ToolParameter(
    name: 'template_id',
    type: 'string',
    description: 'ONLY for read_agent: the `id` string from the Agent Template format '
              . '(e.g. "weather-agent", or "core/core-assistant"). Mutually exclusive with '
              . '`agent_id`; prefer whichever is more convenient. Ignored by every other '
              . 'operation.',
    required: false,
)]
#[ToolParameter(
    name: 'agent_id',
    type: 'integer',
    description: 'ONLY for read_agent: the numeric primary key returned by `create_agent`. '
              . 'Mutually exclusive with `template_id`. Ignored by every other operation.',
    required: false,
)]
final class AgentTool extends AbstractTool
{
    private const APPEND_MODES = ['append', 'prepend'];

    private const NOTES_SEPARATOR = "\n\n";

    /**
     * Shared failure message for every operation that scopes to a specific
     * agent by id. Centralised so the wording stays consistent and the
     * SonarCloud S1192 "duplicate literal" rule stays green as more
     * operations are added.
     */
    private const AGENT_NOT_FOUND = 'Agent not found.';

    public function __construct(
        private readonly AgentServiceInterface $agentService,
        private readonly AgentToolSettingsServiceInterface $toolSettings,
        private readonly AgentTemplateImporter $templateImporter,
        private readonly AgentTemplateValidator $templateValidator,
        private readonly ?PluginLoader $pluginLoader = null,
        private readonly ?ToolIconResolver $iconResolver = null,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'read_agent_configuration'  => $this->readConfiguration($agentId),
            'write_agent_configuration' => $this->writeConfiguration($agentId, $arguments),
            'read_notes'                => $this->readNotes($agentId),
            'write_notes'               => $this->writeNotes($agentId, $arguments, 'append'),
            'write_notes_overwrite'     => $this->writeNotes($agentId, $arguments, 'overwrite'),
            'get_available_tools'       => $this->getAvailableTools($agentId, $userId),
            'create_agent'              => $this->createAgent($userId, $arguments),
            'configure_tools'           => $this->configureTools($agentId, $userId, $arguments),
            'read_agent'                => $this->readAgent($userId, $arguments),
            default                     => ToolResult::fail("Invalid action '{$operation}'."),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = (string) ($arguments['action'] ?? $this->getOperationName($arguments));

        return match ($operation) {
            'read_agent_configuration'  => 'Read this agent\'s configuration.',
            'write_agent_configuration' => 'Update editable fields on this agent.',
            'read_notes'                => 'Read this agent\'s markdown notes.',
            'write_notes'               => sprintf(
                'Write notes on this agent (mode: %s).',
                (string) ($arguments['mode'] ?? 'append'),
            ),
            'write_notes_overwrite'     => 'Replace the agent\'s notes wholesale (destructive).',
            'get_available_tools'       => 'List available tools with configuration status.',
            'create_agent'              => 'Create a new agent from the provided template payload.',
            'configure_tools'           => sprintf(
                'Configure this agent\'s toolset (%d entries).',
                is_array($arguments['tools'] ?? null) ? count($arguments['tools']) : 0,
            ),
            'read_agent'                => sprintf(
                'Read agent state (%s).',
                isset($arguments['template_id']) ? 'template_id: ' . $arguments['template_id']
                    : (isset($arguments['agent_id']) ? 'agent_id: ' . $arguments['agent_id'] : 'no identifier'),
            ),
            default                     => "Agent tool: {$operation}",
        };
    }

    private function readConfiguration(int $agentId): ToolResult
    {
        $agent = $this->agentService->getAgentByAgentId($agentId);
        if ($agent === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }

        $payload = AgentResource::toArray($agent);
        /** @var \Illuminate\Database\Eloquent\Collection<int, \Spora\Models\AgentTool> $agentToolRows */
        $agentToolRows = $agent->agentTools;
        $enabledTools = [];
        foreach ($agentToolRows as $toolRow) {
            $enabledTools[] = [
                'tool_class' => (string) $toolRow->tool_class,
                'tool_name'  => (string) $toolRow->tool_name,
            ];
        }
        $payload['enabled_tools'] = $enabledTools;

        return ToolResult::ok(
            "Configuration for agent #{$agentId} ('{$agent->name}').",
            $payload,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function writeConfiguration(int $agentId, array $arguments): ToolResult
    {
        $patch = (array) ($arguments['agent'] ?? []);
        // Distinguish "no agent object at all" from "agent object only carried
        // `notes`" before stripping — same failure path, different message.
        $hadOnlyNotes = array_keys($patch) === ['notes'];
        // Strip `notes` defensively — write_agent_configuration must never
        // mutate notes; that goes through write_notes / write_notes_overwrite.
        unset($patch['notes']);

        if ($patch === []) {
            return ToolResult::fail(
                $hadOnlyNotes
                    ? 'write_agent_configuration: no editable fields after `notes` was stripped. Use write_notes to mutate notes.'
                    : 'write_agent_configuration: agent object is required.',
            );
        }

        $agent = $this->agentService->updateAgentByAgentId($agentId, $patch);
        if ($agent === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }

        return ToolResult::ok(
            "Updated agent #{$agentId}.",
            AgentResource::toArray($agent),
        );
    }

    private function readNotes(int $agentId): ToolResult
    {
        $agent = $this->agentService->getAgentByAgentId($agentId);
        if ($agent === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }

        $notes = (string) ($agent->notes ?? '');

        return ToolResult::ok(
            "Notes for agent #{$agentId} ({$this->humanBytes(mb_strlen($notes))}).",
            [
                'notes'  => $notes,
                'length' => mb_strlen($notes),
            ],
        );
    }

    /**
     * Apply the calling agent's notes update. Two public operations route
     * through this:
     *   - write_notes           → $mode is 'append' (default) or 'prepend'
     *   - write_notes_overwrite → $mode is 'overwrite' (requires approval)
     *
     * The agent existence check runs first so callers see the right failure
     * even when their input is malformed. Empty content on append/prepend
     * is a no-op so repeated LLM calls don't pile up separators.
     *
     * @param array<string, mixed> $arguments
     */
    private function writeNotes(int $agentId, array $arguments, string $mode): ToolResult
    {
        $agent = $this->agentService->getAgentByAgentId($agentId);
        if ($agent === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }

        $parsed = $this->parseWriteNotesArgs($arguments, $mode);
        if ($parsed instanceof ToolResult) {
            return $parsed;
        }
        [$content, $mode] = $parsed;

        $existing = (string) ($agent->notes ?? '');
        $combined = $this->combineNotes($existing, $content, $mode);

        // No-op: empty content on append/prepend collapses to the
        // existing string in combineNotes(). Skip the DB write to keep
        // updated_at from drifting on no-op calls.
        $isNoop = $combined === $existing;
        if (!$isNoop) {
            // Route through the service so the same EDITABLE_AGENT_FIELDS
            // allowlist applies as everywhere else; no user-ownership check
            // because the orchestrator has pinned the agent id.
            $this->agentService->updateAgentByAgentId($agentId, ['notes' => Utf8Sanitizer::scrubString($combined)]);
        }

        $size = $this->humanBytes(mb_strlen($combined));
        $message = $isNoop
            ? "Notes unchanged ({$size})."
            : "Notes updated via {$mode} ({$size}).";

        return ToolResult::ok(
            $message,
            [
                'notes'  => $combined,
                'length' => mb_strlen($combined),
                'mode'   => $mode,
            ],
        );
    }

    /**
     * Validate the `content` arg and resolve the effective `mode` for the
     * calling operation. Returns [content, mode] on success; a ToolResult
     * on failure. The resolved mode is what the caller should use, so
     * `parseWriteNotesArgs` has to return it (PHP passes scalars by value).
     *
     * @param array<string, mixed> $arguments
     * @return array{0: string, 1: string}|ToolResult
     */
    private function parseWriteNotesArgs(array $arguments, string $defaultMode): array|ToolResult
    {
        if (!array_key_exists('content', $arguments)) {
            return ToolResult::fail('write_notes: content is required.');
        }
        $content = (string) $arguments['content'];
        // write_notes accepts 'append' / 'prepend' (from the LLM's mode
        // argument). write_notes_overwrite ignores the LLM's mode argument
        // — the mode is fixed at the call site (overwrite) because the
        // whole point of the operation is to wipe notes wholesale.
        if ($defaultMode === 'append' || $defaultMode === 'prepend') {
            $requested = (string) ($arguments['mode'] ?? $defaultMode);
            if (!in_array($requested, self::APPEND_MODES, true)) {
                return ToolResult::fail(
                    "write_notes: invalid mode '{$requested}'. Allowed: " . implode(', ', self::APPEND_MODES) . '.',
                );
            }
            $mode = $requested;
        } else {
            // write_notes_overwrite (or any future destructive variant).
            $mode = $defaultMode;
        }
        return [$content, $mode];
    }

    private function getAvailableTools(int $agentId, ?int $userId): ToolResult
    {
        $agent = $this->agentService->getAgentByAgentId($agentId);
        if ($agent === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }

        $userId ??= $agent->user_id;

        $rows = $this->toolSettings->getAllToolsStatus($agentId, $userId) ?? [];
        $operationsByClass = $this->indexOperationsByToolClass(
            $this->toolSettings->getToolsOperations($agentId, $userId) ?? [],
        );
        $enriched = [];
        foreach ($rows as $row) {
            $toolClass = (string) $row['tool_class'];
            $summary   = ToolSchemaPresenter::summarize(
                $toolClass,
                $this->iconResolver?->resolve($toolClass),
            );
            $enriched[] = [
                'tool_class'         => $toolClass,
                'tool_name'          => $summary['tool_name'],
                'display_name'       => $summary['display_name'],
                'description'        => $summary['description'],
                'category'           => $summary['category'],
                'icon'               => $summary['icon'],
                'is_enabled'         => (bool) $row['is_enabled'],
                'needs_configuration' => $row['can_enable'] === false,
                'missing_required'   => $row['missing_required'],
                'operations'         => $summary['operations'],
            ];
        }

        $agentFacing = $this->buildAgentFacingToolRows($enriched, $operationsByClass);
        // `$data` keeps the legacy shape for the operator-facing UI / audit
        // log (`result_data` on the persisted tool_calls row). The LLM only
        // sees the JSON in `$content`.
        $content = json_encode(
            $agentFacing,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return ToolResult::ok($content, $enriched);
    }

    /**
     * Build the LLM-facing payload for `get_available_tools`.
     *
     * Shape (version 1):
     * ```
     * {
     *   "version": 1,
     *   "count": <int>,
     *   "tools": [
     *     {
     *       "tool_class": "Spora\\Tools\\...",
     *       "tool_name": "calculator",
     *       "call_name": "calculator"  |  "tavily:tavily_search"  (plugin-qualified),
     *       "display_name": "Calculator",
     *       "description": "...",
     *       "category": "productivity",
     *       "source": {"kind": "core|plugin|app", "slug": "tavily"|null, "name": "Tavily"|null},
     *       "enabled": <bool>,
     *       "ready_to_enable": <bool>,
     *       "missing_required": ["api_key", ...],
     *       "operations": [{"name": "calculate", "description": "...", "enabled": <bool>, "requires_approval": <bool>}, ...]
     *     }
     *   ]
     * }
     * ```
     *
     * The default response deliberately OMITS full parameter schemas — the
     * LLM can call `create_agent` with `tools[]` entries by `tool_name`
     * without needing the schema. A future `get_tool_details` operation
     * can add schema drill-down.
     *
     * Precedence rules:
     *   - `call_name` is the exact LLM-facing identifier. Plain for core
     *     tools, `<pluginSlug>:<toolName>` for plugin tools (so the LLM
     *     never confuses two plugins that happen to share a plain name).
     *   - `enabled` reflects current `agent_tools` row presence.
     *   - `ready_to_enable` mirrors the operator API's `can_enable`
     *     semantic (no missing required settings). A tool may be enabled
     *     even with missing required settings — the server inserts the
     *     `agent_tools` row and returns a warning, but it is NOT
     *     activatable as `enabled` on the LLM's side without config.
     *   - `missing_required` lists only the required setting keys; no
     *     effective values are exposed to avoid leaking credentials.
     *
     * @param list<array<string, mixed>> $rows Legacy per-agent status rows
     *        (already enriched with display_name, category, icon by the caller).
     * @param array<string, list<array{operation: string, effective_enabled: bool, effective_requires_approval: bool}>> $operationsByClass
     *        Effective per-operation enablement/approval state, keyed by tool_class.
     * @return array<string, mixed>
     */
    private function buildAgentFacingToolRows(
        array $rows,
        array $operationsByClass,
    ): array {
        $tools = [];
        foreach ($rows as $row) {
            $toolClass = (string) $row['tool_class'];
            $toolName  = (string) $row['tool_name'];
            $slug      = $this->pluginLoader?->getSlugForToolClass($toolClass);
            $source    = $this->buildToolSource($toolClass, $slug);
            $toolOperations = $this->buildAgentFacingOperations(
                (array) ($row['operations'] ?? []),
                $operationsByClass[$toolClass] ?? [],
            );
            $tools[] = [
                'tool_class'       => $toolClass,
                'tool_name'        => $toolName,
                'call_name'        => $slug !== null ? "{$slug}:{$toolName}" : $toolName,
                'display_name'     => (string) $row['display_name'],
                'description'      => (string) ($row['description'] ?? ''),
                'category'         => (string) $row['category'],
                'source'           => $source,
                'enabled'          => (bool) $row['is_enabled'],
                'ready_to_enable'  => $row['needs_configuration'] === false,
                'missing_required' => (array) $row['missing_required'],
                'operations'       => $toolOperations,
            ];
        }

        return [
            'version' => 1,
            'count'   => count($tools),
            'tools'   => $tools,
        ];
    }

    /**
     * @param list<array{name: string, description: string, enabledByDefault: bool, requiresApprovalByDefault: bool, discriminatorKey: string}> $declaredOperations
     * @param list<array{operation: string, effective_enabled: bool, effective_requires_approval: bool}> $effectiveOperations
     * @return list<array{name: string, description: string, enabled: bool, requires_approval: bool}>
     */
    private function buildAgentFacingOperations(
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
     * @return array{kind: string, slug: string|null, name: string|null}
     */
    private function buildToolSource(string $toolClass, ?string $pluginSlug): array
    {
        if ($pluginSlug !== null) {
            $manifest = $this->pluginLoader?->getPluginManifest($pluginSlug);
            $name = is_array($manifest) ? ($manifest['name'] ?? null) : null;
            return [
                'kind' => 'plugin',
                'slug' => $pluginSlug,
                'name' => is_string($name) && $name !== '' ? $name : null,
            ];
        }
        // No plugin owns the class. Distinguish "core" (ships with spora-core)
        // from "app" (registered by the operator's app/App.php) by checking
        // whether the class is in the core tool_classes list — that's the
        // closest signal we have without an explicit App-vs-core flag.
        $coreClasses = [
            'Spora\\Tools\\TimeTool',
            'Spora\\Tools\\CalculatorTool',
            'Spora\\Tools\\ReadUrlTool',
            'Spora\\Tools\\UserInfoTool',
            'Spora\\Tools\\HandoverTool',
            'Spora\\Tools\\AgentTool',
            'Spora\\Tools\\SkillTool',
        ];
        return [
            'kind' => in_array($toolClass, $coreClasses, true) ? 'core' : 'app',
            'slug' => null,
            'name' => null,
        ];
    }

    /**
     * @param list<array{tool_class: string, operation: string, effective_enabled: bool, effective_requires_approval: bool}> $operations
     * @return array<string, list<array{operation: string, effective_enabled: bool, effective_requires_approval: bool}>>
     */
    private function indexOperationsByToolClass(array $operations): array
    {
        $indexed = [];
        foreach ($operations as $op) {
            $indexed[(string) $op['tool_class']][] = $op;
        }
        return $indexed;
    }

    /**
     * Run the shared pre-import guards (user, payload, validator) and
     * return a `ToolResult` on the first failure, or the validated payload
     * on success. Kept out of createAgent() to drop that method below the
     * SonarCloud S1142 3-return ceiling.
     *
     * @param array<string, mixed> $arguments
     * @return array{userId: int, payload: array<string, mixed>}|ToolResult
     */
    private function prepareCreateAgent(?int $userId, array $arguments): array|ToolResult
    {
        $payload = (array) ($arguments['payload'] ?? []);
        $validation = $this->templateValidator->validate($payload);
        // Collapse the three independent failure paths into a single match
        // arm so the method stays under the S1142 3-return ceiling.
        $error = match (true) {
            $userId === null
                => 'create_agent requires an authenticated user.',
            $payload === []
                => 'create_agent: payload object is required.',
            !$validation->isValid()
                => 'create_agent: payload failed validation: '
                   . $this->summarizeValidationErrors($validation)
                   . ' Re-read the agent-creation skill (skill action: read, name: agent-creation, '
                   . 'filename: SKILL.md) for the exact schema.',
            default => null,
        };
        if ($error !== null) {
            return ToolResult::fail($error);
        }
        return ['userId' => $userId, 'payload' => $payload];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function createAgent(?int $userId, array $arguments): ToolResult
    {
        $prepared = $this->prepareCreateAgent($userId, $arguments);
        if ($prepared instanceof ToolResult) {
            return $prepared;
        }

        $result = $this->templateImporter->importPayload($prepared['userId'], $prepared['payload']);

        return ToolResult::ok(
            "Created agent #{$result->agent->id} ('{$result->agent->name}').",
            [
                'agent'         => AgentResource::toArray($result->agent),
                'tools_enabled' => $result->toolsEnabled,
                'warnings'      => $result->warnings,
            ],
        );
    }

    /**
     * Apply a toolset + per-operation overrides to the calling agent.
     *
     * Two-phase agent creation pairs this with `create_agent`: the LLM
     * creates the skeletal agent first, then calls `configure_tools` to
     * enable tools and per-operation overrides. This avoids forcing the
     * LLM to make N nested decisions (one per tools[i].operations[j])
     * inside a single approved `create_agent` call.
     *
     * The implementation routes through `AgentToolSettingsServiceInterface`
     * — the existing operator-side surface — so the LLM-facing path and
     * the operator-facing API share the same enable / override semantics.
     *
     * @param array<string, mixed> $arguments
     */
    private function configureTools(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        if ($userId === null) {
            return ToolResult::fail('configure_tools requires an authenticated user.');
        }
        $entries = $arguments['tools'] ?? [];
        if (!is_array($entries) || ($entries !== [] && !array_is_list($entries))) {
            return ToolResult::fail('configure_tools: `tools` must be an array.');
        }

        // Validate every entry before mutating anything so a half-applied
        // toolset never lands in the database. Collect the per-entry work
        // plan, then apply it once we know the whole input is well-formed.
        $plan = [];
        foreach ($entries as $i => $entry) {
            $parsed = $this->parseConfigureToolEntry($entry, $i, $userId);
            if ($parsed instanceof ToolResult) {
                return $parsed;
            }
            $plan[] = $parsed;
        }

        foreach ($plan as $step) {
            if ($step['enable']) {
                $this->toolSettings->enableTool($agentId, $userId, $step['tool_class']);
            } else {
                $this->toolSettings->disableTool($agentId, $userId, $step['tool_class']);
            }
            foreach ($step['operations'] as $op) {
                $this->toolSettings->patchOperationOverride(
                    $agentId,
                    $userId,
                    $step['tool_class'],
                    $op['name'],
                    [
                        'enabled'                   => $op['enabled'] ? 1 : 0,
                        'default_requires_approval' => $op['auto_approve'] ? 0 : 1,
                    ],
                );
            }
        }

        // Return the post-change effective state via getAvailableTools so
        // the LLM sees the same wire shape it would have seen from a
        // follow-up get_available_tools call.
        return $this->getAvailableTools($agentId, $userId);
    }

    /**
     * Validate one `tools[i]` entry and return the work plan, or a
     * ToolResult on failure.
     *
     * @param mixed $entry
     * @return array{tool_class: string, enable: bool, operations: list<array{name: string, enabled: bool, auto_approve: bool}>}|ToolResult
     */
    private function parseConfigureToolEntry($entry, int $i, int $userId): array|ToolResult
    {
        if (!is_array($entry)) {
            return ToolResult::fail("configure_tools: tool entry #{$i} must be an object.");
        }
        $toolClass = is_string($entry['tool_class'] ?? null) ? $entry['tool_class'] : '';
        if ($toolClass === '') {
            return ToolResult::fail("configure_tools: tool entry #{$i} is missing `tool_class`.");
        }
        $enable = (bool) ($entry['enabled'] ?? true);

        $operations = [];
        $ops = $entry['operations'] ?? [];
        if (is_array($ops) && $ops !== []) {
            foreach ($ops as $j => $op) {
                if (!is_array($op) || !isset($op['name']) || !is_string($op['name']) || $op['name'] === '') {
                    return ToolResult::fail(
                        "configure_tools: operations[{$i}][{$j}] must be `{name, enabled?, auto_approve?}`.",
                    );
                }
                $operations[] = [
                    'name'         => $op['name'],
                    'enabled'      => (bool) ($op['enabled'] ?? true),
                    'auto_approve' => (bool) ($op['auto_approve'] ?? false),
                ];
            }
        }

        return ['tool_class' => $toolClass, 'enable' => $enable, 'operations' => $operations];
    }

    /**
     * Read a specific agent's full state by `template_id` or `agent_id`.
     *
     * This is the only AgentTool operation that accepts an agent
     * identifier — it exists so the LLM can verify what `create_agent`
     * and `configure_tools` actually committed. Both inputs are scoped to
     * the authenticated user: cross-user reads are refused, never
     * transparently returned as "not found" only when the agent exists.
     *
     * @param array<string, mixed> $arguments
     */
    private function readAgent(?int $userId, array $arguments): ToolResult
    {
        if ($userId === null) {
            return ToolResult::fail('read_agent requires an authenticated user.');
        }
        $templateId = is_string($arguments['template_id'] ?? null) ? trim((string) $arguments['template_id']) : '';
        $agentIdRaw = $arguments['agent_id'] ?? null;
        $agentId    = is_int($agentIdRaw) ? $agentIdRaw
            : (is_numeric($agentIdRaw) ? (int) $agentIdRaw : 0);
        if ($templateId === '' && $agentId === 0) {
            return ToolResult::fail('read_agent: either `template_id` or `agent_id` is required.');
        }

        $agent = $this->resolveReadAgentTarget($userId, $templateId, $agentId);
        if ($agent === null) {
            return ToolResult::fail('Agent not found or not owned by this user.');
        }

        $payload = AgentResource::toArray($agent);
        /** @var \Illuminate\Database\Eloquent\Collection<int, \Spora\Models\AgentTool> $agentToolRows */
        $agentToolRows = $agent->agentTools;
        $enabledTools = [];
        foreach ($agentToolRows as $toolRow) {
            $enabledTools[] = [
                'tool_class' => (string) $toolRow->tool_class,
                'tool_name'  => (string) $toolRow->tool_name,
            ];
        }
        $payload['enabled_tools'] = $enabledTools;

        return ToolResult::ok(
            "Configuration for agent #{$agent->id} ('{$agent->name}').",
            $payload,
        );
    }

    /**
     * Resolve the read_agent target to an Agent row owned by $userId.
     *
     * `template_id` takes precedence over `agent_id` when both are
     * supplied. `template_id` currently resolves only when the agents
     * table exposes the column; if it does not, the LLM is expected to
     * supply `agent_id` (the primary key returned by `create_agent`).
     * Returns null on miss — the caller surfaces the standard failure.
     */
    private function resolveReadAgentTarget(int $userId, string $templateId, int $agentId): ?Agent
    {
        if ($templateId !== '') {
            $schema = (new Agent())->getConnection()->getSchemaBuilder();
            if ($schema->hasColumn('agents', 'template_id')) {
                return Agent::query()
                    ->where('user_id', $userId)
                    ->where('template_id', $templateId)
                    ->first();
            }
            // Fall back to the primary key — the LLM sometimes echoes the
            // template id back as a numeric pk.
            if (is_numeric($templateId)) {
                return Agent::query()
                    ->where('user_id', $userId)
                    ->where('id', (int) $templateId)
                    ->first();
            }
            return null;
        }
        return Agent::query()
            ->where('user_id', $userId)
            ->where('id', $agentId)
            ->first();
    }

    /**
     * Concatenate $content with $existing per the chosen mode. The separator
     * is a fixed blank line per product decision — operators see a clean
     * markdown break between segments and the agent does not get to choose
     * a custom joiner.
     */
    private function combineNotes(string $existing, string $content, string $mode): string
    {
        // Empty content on append/prepend is a no-op so repeated LLM calls
        // don't pile up separators. Overwrite wipes existing wholesale;
        // empty-existing collapses to plain content for both modes. Match(true)
        // keeps the function under SonarCloud S1142's 3-return ceiling.
        $separator = self::NOTES_SEPARATOR;
        return match (true) {
            $content === ''                   => $existing,
            $existing === '' || $mode === 'overwrite' => $content,
            $mode === 'prepend'               => $content . $separator . $existing,
            default                           => $existing . $separator . $content,
        };
    }

    private function humanBytes(int $length): string
    {
        return $length . ' chars';
    }

    private function summarizeValidationErrors(ValidationResult $result): string
    {
        $messages = [];
        foreach ($result->errors() as $error) {
            $messages[] = $error['message'];
        }
        return implode('; ', $messages);
    }
}

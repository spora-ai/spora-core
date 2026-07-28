<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Models\Agent;
use Spora\Plugins\PluginLoader;
use Spora\Services\AgentManifest;
use Spora\Services\AgentManifestRenderer;
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
 *   - read_agent               (disabled, no approval; takes optional agent_id,
 *                                otherwise reads the calling agent)
 *   - read_agent_configuration (DEPRECATED — soft-redirected to read_agent(self);
 *                                kept for one release to avoid breaking historical
 *                                tasks that learned the old name)
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
// `read_agent_configuration` is no longer a class-level ToolOperation —
// it duplicates `read_agent(self)`. The legacy name is soft-redirected
// to `read_agent({})` in {@see self::execute()} below.
#[ToolOperation(
    name: 'write_agent_configuration',
    description: 'Update editable fields on this agent (name, description, system_prompt, max_steps, '
               . 'allow_followup, retry_after_minutes, max_retries, is_pinned, is_archived, is_favorite). '
               . 'Notes MUST go through write_notes / write_notes_overwrite — they are stripped from '
               . 'this patch. Unknown keys (llm_driver_config_id, anything else outside the allowlist) '
               . 'are silently dropped at the database layer; call read_agent afterwards '
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
    description: 'Create a new agent from a slim payload: `name` (required, top level), '
               . '`description`, `system_prompt`, `max_steps`, `allow_followup`, '
               . '`retry_after_minutes`, `max_retries`, `required_plugins` (array of '
               . 'plugin slugs). Do NOT wrap fields in an `agent{}` block; do NOT '
               . 'send a `tools[]` block here — after the agent row exists, call '
               . '`configure_tools(agent_id: N, tools: [...])` to apply a toolset, '
               . 'then `read_agent(agent_id: N)` to verify. The full '
               . 'agent-template schema (id/version/agent{}/tools[]) is reserved '
               . 'for the operator-upload endpoint at '
               . 'POST /api/v1/agent-templates/import and will be rejected on this '
               . 'surface. Read the agent-creation skill first (skill action: read, '
               . 'name: agent-creation, filename: SKILL.md).',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'configure_tools',
    description: 'Enable or disable tools and per-operation overrides on an agent. '
               . 'Takes `agent_id` (the numeric pk returned by `create_agent`; '
               . 'omit to operate on the calling agent) and a `tools` list of '
               . '`{ tool_class, enabled, operations: [{name, enabled?, auto_approve?}] }`. '
               . 'A tool with `enabled: false` removes it from the agent. '
               . 'Omit `operations` to inherit defaults; pass `[{name:"now"}]` to enable one, '
               . '`[{name:"now", enabled:false}]` to disable one, '
               . '`[{name:"now", auto_approve:true}]` to enable auto-approve. '
               . 'Returns the canonical agent manifest (Markdown wrapper + '
               . 'structured JSON) so you can verify what landed without a '
               . 'follow-up `read_agent` call.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'read_agent',
    description: 'Read the full configuration of a specific agent — `id`, `name`, `description`, '
               . '`system_prompt`, enabled tools, and per-operation `enabled` / `requires_approval` state. '
               . 'Use this to verify what was actually committed by `create_agent` and `configure_tools` — '
               . 'silent drops do occur, so the read-back is the source of truth. '
               . 'Identify the target by `agent_id` (the numeric primary key returned by `create_agent`). '
               . 'Omit `agent_id` to read the calling agent (same as the deprecated '
               . '`read_agent_configuration` operation).',
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
              . 'read_notes, write_notes, write_notes_overwrite, get_available_tools, '
              . 'configure_tools, read_agent, or create_agent.',
    required: ['write_agent_configuration'],
)]
#[ToolParameter(
    name: 'content',
    type: 'string',
    description: 'ONLY for write_notes and write_notes_overwrite: the markdown segment to '
              . 'write. Combined with `mode` against the current notes. Ignored by every '
              . 'other operation; omit this key entirely when calling read_notes, '
              . 'write_agent_configuration, get_available_tools, or create_agent.',
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
    description: 'ONLY for create_agent: a slim Agent record — top-level '
              . '`name` (required), `description`, `system_prompt`, `max_steps`, '
              . '`allow_followup`, `retry_after_minutes`, `max_retries`, and '
              . '`required_plugins` (array of plugin slugs). Do NOT nest fields '
              . 'under `agent{}` and do NOT send `tools[]` — the LLM-facing flow '
              . 'is two-phase (create_agent → configure_tools(agent_id: N) → '
              . 'read_agent(agent_id: N)). The full Agent Template shape '
              . '(with id/version/agent{}/tools[]) is reserved for the '
              . 'operator-upload endpoint at POST /api/v1/agent-templates/import. '
              . 'See the agent-creation skill (skill action: read, name: '
              . 'agent-creation, filename: SKILL.md) for the exact shape and '
              . 'Common-mistakes table. Ignored by every other operation.',
    required: ['create_agent'],
)]
#[ToolParameter(
    name: 'tools',
    type: 'array',
    description: 'ONLY for configure_tools: a list of `{ tool_class, enabled, operations: [...] }` entries. '
              . 'Each operation entry may set `enabled` (default true) and `auto_approve` (default false). '
              . 'A tool with `enabled: false` removes it from the agent. '
              . 'Ignored by every other operation; omit this key entirely when calling '
              . 'read_notes, write_notes, write_notes_overwrite, write_agent_configuration, '
              . 'get_available_tools, create_agent, or read_agent.',
    required: ['configure_tools'],
)]
#[ToolParameter(
    name: 'agent_id',
    type: 'integer',
    description: 'ONLY for read_agent and configure_tools. '
              . 'For read_agent: numeric primary key returned by `create_agent`. Omit to read the calling agent. '
              . 'For configure_tools: numeric primary key of the agent to configure (default: the calling agent). '
              . 'Pass the value from `create_agent` to target a freshly-created agent. '
              . 'Ignored by every other operation.',
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

    /**
     * Prefix on every `configure_tools` validation failure message.
     * Centralised to keep the S1192 "duplicate literal" rule green and to
     * let consumers locate every fail site with a single grep.
     */
    private const CONFIGURE_TOOLS_ERR_PREFIX = 'configure_tools: ';

    /**
     * Prefix on every `read_agent` validation failure message. Same
     * rationale as {@see self::CONFIGURE_TOOLS_ERR_PREFIX}.
     */
    private const READ_AGENT_ERR_PREFIX = 'read_agent: ';

    public function __construct(
        private readonly AgentServiceInterface $agentService,
        private readonly AgentToolSettingsServiceInterface $toolSettings,
        private readonly AgentManifest $manifest,
        private readonly ?PluginLoader $pluginLoader = null,
        private readonly ?ToolIconResolver $iconResolver = null,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        // Soft-redirect the deprecated read_agent_configuration to
        // read_agent(self). One read action surfaces from the LLM's
        // side; the legacy name keeps returning manifest shape for
        // any task that learned the old enum. Hard-remove in a later
        // release.
        if ($operation === 'read_agent_configuration') {
            return $this->redirectReadAgentConfiguration($agentId, $userId);
        }

        return match ($operation) {
            'write_agent_configuration' => $this->writeConfiguration($agentId, $arguments),
            'read_notes'                => $this->readNotes($agentId),
            'write_notes'               => $this->writeNotes($agentId, $arguments, 'append'),
            'write_notes_overwrite'     => $this->writeNotes($agentId, $arguments, 'overwrite'),
            'get_available_tools'       => $this->getAvailableTools($agentId, $userId),
            'create_agent'              => $this->createAgent($userId, $arguments),
            'configure_tools'           => $this->configureTools($agentId, $userId, $arguments),
            'read_agent'                => $this->readAgent($agentId, $userId, $arguments),
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
                isset($arguments['agent_id']) && is_scalar($arguments['agent_id']) && (int) $arguments['agent_id'] > 0
                    ? 'agent_id: ' . (int) $arguments['agent_id']
                    : 'calling agent',
            ),
            default                     => "Agent tool: {$operation}",
        };
    }

    // `readConfiguration` (used by the deprecated `read_agent_configuration`
    // operation) used to live here. The dispatch now redirects to
    // `read_agent(self)` directly via
    // {@see self::redirectReadAgentConfiguration()} so the method is gone.

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

        // `$agent` already carries `user_id` from `updateAgentByAgentId` —
        // enough for {@see AgentManifest::toArray()} to look up the
        // per-agent tool rows. Re-reading through `getAgent` would just
        // re-hit the DB for an unchanged row.
        return $this->renderManifestResult($agent);
    }

    /**
     * Build a {@see ToolResult} carrying the canonical manifest as
     * `result_data` and the {@see AgentManifestRenderer} Markdown as
     * `result_content`. Used by every read/write path that has an Agent
     * row in hand (read_agent_configuration, write_agent_configuration,
     * read_agent, configure_tools confirmation, create_agent confirmation).
     */
    private function renderManifestResult(Agent $agent): ToolResult
    {
        $manifest = $this->manifest->toArray($agent);
        return ToolResult::ok(AgentManifestRenderer::markdown($manifest), $manifest);
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
            // `$enriched` retains the legacy operator-side shape (used as
            // `result_data` on the persisted tool_calls row) so the
            // operator-facing UI's `tool_name` / `category` / `icon`
            // expectations stay intact. The LLM-facing `$content`
            // (`$agentFacing` below) carries the slimmer v2 shape.
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
     * Shape (version 2):
     * ```
     * {
     *   "version": 2,
     *   "count": <int>,
     *   "tools": [
     *     {
     *       "tool_class": "Spora\\Tools\\...",
     *       "display_name": "Calculator",
     *       "description": "...",
     *       "plugin_slug": null | "tavily",
     *       "enabled": <bool>,
     *       "ready_to_enable": <bool>,
     *       "missing_required": ["api_key", ...],
     *       "operations": [{"name": "calculate", "description": "...", "enabled": <bool>, "requires_approval": <bool>}, ...]
     *     }
     *   ]
     * }
     * ```
     *
     * Why version 2: the v1 payload exposed `tool_name`, `call_name`,
     * `category`, `source`, and `icon`. Each is useful for the operator
     * UI but redundant for the LLM — `tool_name` overlaps with
     * `tool_class`, `call_name` is for tool invocation rather than
     * agent configuration, `category` and `icon` are operator-UI
     * concerns, and `source` is a debugger's-eye view. The LLM only
     * needs the FQCN (for `configure_tools`), the current enabled
     * state, the missing-config sentinel, and the per-operation
     * details. v2 cuts per-tool payload size roughly in half.
     *
     * Precedence rules:
     *   - `tool_class` is the FQCN the LLM passes to `configure_tools`.
     *     Use `plugin_slug` (or null for core) for `required_plugins[]`.
     *   - `enabled` reflects current `agent_tools` row presence.
     *   - `ready_to_enable` mirrors the operator API's `can_enable`
     *     semantic (no missing required settings). A tool may be enabled
     *     even with missing required settings — the server inserts the
     *     `agent_tools` row and returns a warning, but it is NOT
     *     activatable as `enabled` on the LLM's side without config.
     *   - `missing_required` lists only the required setting keys; no
     *     effective values are exposed to avoid leaking credentials.
     *
     * The default response deliberately OMITS full parameter schemas — the
     * LLM can call `configure_tools` with `operations: [{name, ...}]`
     * entries without needing the schema. A future `get_tool_details`
     * operation can add schema drill-down.
     *
     * @param list<array<string, mixed>> $rows Per-agent status rows
     *        (already enriched with display_name, description by the caller).
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
            $pluginSlug = $this->pluginLoader?->getSlugForToolClass($toolClass);
            $toolOperations = $this->buildAgentFacingOperations(
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
     * Slim `create_agent` payload validator. Returns either the prepared
     * AgentService-ready data shape or a failure ToolResult. Extracted
     * from {@see self::createAgent()} so the dispatcher stays under the
     * SonarCloud S1142 3-return ceiling.
     *
     * Slim shape (mirrors {@see AgentService::createAgent()}):
     *   - name                  required, 1..200 chars
     *   - description           optional, ≤2000 chars
     *   - system_prompt        optional
     *   - max_steps             optional int 1..100, default 10
     *   - allow_followup        optional bool, default true
     *   - retry_after_minutes   optional int, default 0
     *   - max_retries          optional int, default 0
     *   - required_plugins      optional list<string> of slugs
     *
     * The agent-template.schema.json shape (id, name, version, agent{},
     * required_plugins[]) is reserved for the operator-upload
     * endpoint at POST /api/v1/agent-templates/import. Driving the
     * operator flow through an AgentTool's LLM-facing surface was the
     * root cause of the task #46 failures — too many nested keys, too
     * easy to put `name` inside agent{} or send `required_plugins` as
     * `{item: "..."}` instead of `["..."]`.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    private function validateCreateAgentPayload(?int $userId, array $arguments): array|ToolResult
    {
        $raw = $arguments['payload'] ?? null;
        // The match collapses the four independent failure paths into
        // one branch so this method stays under the 3-return ceiling.
        $error = match (true) {
            $userId === null
                => 'create_agent requires an authenticated user.',
            !is_array($raw) || $raw === []
                => 'create_agent: `payload` object is required.',
            isset($raw['agent']) && is_array($raw['agent'])
                => 'create_agent: send a slim payload (name, description, system_prompt, ...) — '
                   . 'do NOT wrap fields in an `agent{}` block. See the agent-creation skill '
                   . '(skill action: read, name: agent-creation, filename: SKILL.md).',
            isset($raw['tools']) && $raw['tools'] !== []
                => 'create_agent: `tools[]` is no longer accepted here. Create the agent first, '
                   . 'then call `configure_tools(agent_id: N, tools: [...])` to apply a toolset. '
                   . 'See the agent-creation skill.',
            default => $this->createAgentPayloadErrors($raw),
        };
        if ($error instanceof ToolResult) {
            return $error;
        }
        if (is_string($error)) {
            return ToolResult::fail($error);
        }
        // Also verify the name. The error list above leaves the
        // unset-name case unreported — surface it here.
        $name = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
        if ($name === '' || strlen($name) > 200) {
            return ToolResult::fail(
                'create_agent: `name` is required (1..200 chars). '
                . 'Send `name: "..."` at the top level of the payload, not inside `agent{}`.',
            );
        }
        // Normalize into AgentService::createAgent's expected shape.
        return [
            'name'                => $name,
            'description'         => is_string($raw['description'] ?? null) ? $raw['description'] : null,
            'system_prompt'       => is_string($raw['system_prompt'] ?? null) ? $raw['system_prompt'] : null,
            'llm_driver_config_id' => null,
            'max_steps'           => (int) ($raw['max_steps'] ?? 10),
            'allow_followup'      => (bool) ($raw['allow_followup'] ?? true),
        ];
    }

    /**
     * Validate the slim payload's option keys (`max_steps`,
     * `allow_followup`, `retry_after_minutes`, `max_retries`,
     * `required_plugins`). Returns null on success or a ToolResult on
     * failure. Pulled out so {@see self::validateCreateAgentPayload()}
     * stays under the S1142 3-return ceiling.
     *
     * @param mixed $raw
     */
    private function createAgentPayloadErrors(mixed $raw): ?ToolResult
    {
        if (!is_array($raw)) {
            return ToolResult::fail('create_agent: `payload` object is required.');
        }
        // Each rule emits a literal "send X instead" example so the
        // LLM can copy-paste the fix rather than guess.
        if (array_key_exists('max_steps', $raw)
            && (!is_int($raw['max_steps']) || $raw['max_steps'] < 1 || $raw['max_steps'] > 100)
        ) {
            return ToolResult::fail(
                'create_agent: `max_steps` must be an integer in 1..100. '
                . 'Send `"max_steps": 10` (note: not a string).',
            );
        }
        if (array_key_exists('allow_followup', $raw) && !is_bool($raw['allow_followup'])) {
            return ToolResult::fail(
                'create_agent: `allow_followup` must be a boolean. '
                . 'Send `"allow_followup": true`, not the string `"true"`.',
            );
        }
        if (array_key_exists('retry_after_minutes', $raw)
            && (!is_int($raw['retry_after_minutes']) || $raw['retry_after_minutes'] < 0)
        ) {
            return ToolResult::fail(
                'create_agent: `retry_after_minutes` must be a non-negative integer.',
            );
        }
        if (array_key_exists('max_retries', $raw)
            && (!is_int($raw['max_retries']) || $raw['max_retries'] < 0)
        ) {
            return ToolResult::fail(
                'create_agent: `max_retries` must be a non-negative integer.',
            );
        }
        if (array_key_exists('required_plugins', $raw)) {
            $plugins = $raw['required_plugins'];
            // `required_plugins` arrived from the LLM as a string, an
            // object (the `{item: "weather"}` OpenAI serialization wrap),
            // or an array of strings. Accept only the canonical array
            // shape — the failure message tells the LLM exactly what
            // to send.
            if (!is_array($plugins) || (!$this->isListOfStrings($plugins) && $plugins !== [])) {
                return ToolResult::fail(
                    'create_agent: `required_plugins` must be an array of strings. '
                    . 'Send `"required_plugins": ["weather"]`, not `{"item": "weather"}` or `["weather"]` '
                    . 'wrapped in an object. Get slugs from `get_available_tools` under the `plugin_slug` field.',
                );
            }
        }
        return null;
    }

    /**
     * @param mixed $value
     */
    private function isListOfStrings(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        if ($value === []) {
            return true;
        }
        if (!array_is_list($value)) {
            return false;
        }
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function createAgent(?int $userId, array $arguments): ToolResult
    {
        $data = $this->validateCreateAgentPayload($userId, $arguments);
        if ($data instanceof ToolResult) {
            return $data;
        }

        $agent = $this->agentService->createAgent($userId, $data);
        // Output the canonical manifest + a one-line "next steps"
        // pointer. The LLM gets the same shape it would get from a
        // follow-up `read_agent(agent_id: N)`.
        $intro = "Created agent #{$agent->id} ('{$agent->name}'). "
               . "Configure tools next with `configure_tools(agent_id: {$agent->id}, tools: [...])` "
               . "and verify with `read_agent(agent_id: {$agent->id})`.";
        $manifest = $this->manifest->toArray($agent);
        return ToolResult::ok(
            $intro . "\n\n" . AgentManifestRenderer::markdown($manifest),
            $manifest,
        );
    }

    /**
     * Apply a toolset + per-operation overrides.
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
     * Target resolution: when `agent_id` is supplied in `$arguments`,
     * operate on that agent (cross-user reads return not-found). When
     * omitted, operate on the calling agent — the original in-place
     * semantics preserved for back-compat.
     *
     * The result is the canonical agent manifest (Markdown +
     * result_data) — the LLM can verify what `configure_tools` actually
     * committed without a follow-up `read_agent` call.
     *
     * @param array<string, mixed> $arguments
     */
    private function configureTools(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        // Validate user + payload shape BEFORE the target resolver so
        // malformed inputs surface the schema validation error without
        // a wasted DB lookup. Order mirrors {@see createAgent()}.
        $entries = $arguments['tools'] ?? [];
        $planOrFail = match (true) {
            $userId === null
                => ToolResult::fail('configure_tools requires an authenticated user.'),
            !is_array($entries) || ($entries !== [] && !array_is_list($entries))
                => ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . '`tools` must be an array.'),
            default => $this->buildConfigureToolsPlan($entries),
        };
        if ($planOrFail instanceof ToolResult) {
            return $planOrFail;
        }

        $target = $this->resolveAgentToolTarget($userId, $agentId, $arguments);
        if ($target instanceof ToolResult) {
            return $target;
        }
        $this->applyConfigureToolsPlan($target->id, $userId, $planOrFail);
        // Re-read after the apply so `renderManifestResult` sees the
        // post-change rows + override state.
        $fresh = Agent::find($target->id);
        if ($fresh === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }
        return $this->renderManifestResult($fresh);
    }

    /**
     * Validate every entry in `tools` and return the work plan, or a
     * failure ToolResult on the first malformed entry.
     *
     * @param  mixed $entries
     * @return list<array{tool_class: string, enable: bool, operations: list<array{name: string, enabled: bool, auto_approve: bool}>}>|ToolResult
     */
    private function buildConfigureToolsPlan(mixed $entries): array|ToolResult
    {
        $plan = [];
        foreach ($entries as $i => $entry) {
            $step = $this->parseConfigureToolEntry($entry, $i);
            if ($step instanceof ToolResult) {
                return $step;
            }
            $plan[] = $step;
        }
        return $plan;
    }

    /**
     * Validate one `tools[i]` entry and return the work step, or a
     * ToolResult on failure.
     *
     * @param  mixed $entry
     * @return array{tool_class: string, enable: bool, operations: list<array{name: string, enabled: bool, auto_approve: bool}>}|ToolResult
     */
    private function parseConfigureToolEntry(mixed $entry, int $i): array|ToolResult
    {
        // The two object-shape checks collapse through {@see self::shapeToolEntryFailure()}
        // into one branch — combined with the operations-fail branch and
        // the success return, this method stays at three returns total
        // (S1142 ceiling).
        $shapeFail = $this->shapeToolEntryFailure($entry, $i);
        if ($shapeFail !== null) {
            return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . $shapeFail);
        }
        $toolClass = (string) ($entry['tool_class'] ?? '');

        $operations = $this->parseConfigureToolOperations($entry['operations'] ?? [], $i);
        if ($operations instanceof ToolResult) {
            return $operations;
        }
        return ['tool_class' => $toolClass, 'enable' => (bool) ($entry['enabled'] ?? true), 'operations' => $operations];
    }

    /**
     * Return the failure message for the two object-shape checks, or null
     * when the entry passes both. Extracted so {@see self::parseConfigureToolEntry()}
     * stays under the SonarCloud S1142 3-return ceiling.
     */
    private function shapeToolEntryFailure(mixed $entry, int $i): ?string
    {
        if (!is_array($entry)) {
            return "tool entry #{$i} must be an object.";
        }
        if (!isset($entry['tool_class']) || !is_string($entry['tool_class']) || $entry['tool_class'] === '') {
            return "tool entry #{$i} is missing `tool_class`.";
        }
        return null;
    }

    /**
     * Validate a `tools[i].operations` value and return the typed list, or
     * a failure ToolResult on the first malformed operation. Empty /
     * missing operations is legal — the operation default then applies.
     *
     * @param  mixed $ops
     * @return list<array{name: string, enabled: bool, auto_approve: bool}>|ToolResult
     */
    private function parseConfigureToolOperations(mixed $ops, int $i): array|ToolResult
    {
        if (!is_array($ops) || $ops === []) {
            return [];
        }
        $out = [];
        foreach ($ops as $j => $op) {
            if (!is_array($op) || !isset($op['name']) || !is_string($op['name']) || $op['name'] === '') {
                return ToolResult::fail(
                    self::CONFIGURE_TOOLS_ERR_PREFIX . "operations[{$i}][{$j}] must be `{name, enabled?, auto_approve?}`.",
                );
            }
            $out[] = [
                'name'         => $op['name'],
                'enabled'      => (bool) ($op['enabled'] ?? true),
                'auto_approve' => (bool) ($op['auto_approve'] ?? false),
            ];
        }
        return $out;
    }

    /**
     * Apply the validated `configure_tools` plan. Separated from
     * {@see self::configureTools()} so the validator stays under the
     * SonarCloud S1142 3-return ceiling.
     *
     * @param  list<array{tool_class: string, enable: bool, operations: list<array{name: string, enabled: bool, auto_approve: bool}>}> $plan
     */
    private function applyConfigureToolsPlan(int $agentId, int $userId, array $plan): void
    {
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
    }

    /**
     * Read a specific agent's full state by `agent_id`.
     *
     * `agent_id` is optional — omit to read the calling agent (same
     * semantics the deprecated `read_agent_configuration` operation
     * had). This is the only AgentTool operation that accepts an agent
     * identifier beyond the calling agent — it exists so the LLM can
     * verify what `create_agent` and `configure_tools` actually
     * committed. Input is scoped to the authenticated user: cross-user
     * reads are refused, never silently substituted.
     *
     * @param array<string, mixed> $arguments
     */
    private function readAgent(int $callingAgentId, ?int $userId, array $arguments): ToolResult
    {
        if ($userId === null) {
            return ToolResult::fail('read_agent requires an authenticated user.');
        }
        $target = $this->resolveAgentToolTarget($userId, $callingAgentId, $arguments);
        if ($target instanceof ToolResult) {
            return $target;
        }
        return $this->renderManifestResult($target);
    }

    /**
     * Soft-redirect for the deprecated `read_agent_configuration`
     * operation. Routes to `read_agent(self)` and prepends a single
     * deprecation note to the result content so any LLM that learned
     * the old name still gets a usable response while the next release
     * hard-removes the operation.
     */
    private function redirectReadAgentConfiguration(int $callingAgentId, ?int $userId): ToolResult
    {
        $result = $this->readAgent($callingAgentId, $userId, []);
        if (!$result->success) {
            return $result;
        }
        return ToolResult::ok(
            "_(deprecated: read_agent_configuration — use `read_agent` without `agent_id`)_\n\n"
            . $result->content,
            $result->data,
        );
    }

    /**
     * Validate and resolve the target agent for `configure_tools` and
     * `read_agent`. Three input modes drive the behavior:
     *
     *   1. `agent_id` supplied and positive → look up that agent, scoped
     *      to the authenticated user. Cross-user reads return "not
     *      found" without ever exposing the agent's payload.
     *   2. `agent_id` omitted → fall back to the calling agent
     *      ($callingAgentId), scoped to the authenticated user. This is
     *      the in-place edit semantics both operations used to have
     *      before `agent_id` was added.
     *   3. `agent_id` malformed (zero / non-numeric) → fail with a
     *      clear validation message so the LLM knows what to retry.
     *
     * @param  array<string, mixed> $arguments
     * @return Agent|ToolResult
     */
    private function resolveAgentToolTarget(int $userId, int $callingAgentId, array $arguments): Agent|ToolResult
    {
        // `template_id` was the legacy read_agent identifier before
        // agent_id took over (PR #170). Today multiple agents can share
        // a template label, so it can never resolve to a single row.
        // Refusing it explicitly is cheaper than silently misrouting.
        if (array_key_exists('template_id', $arguments)) {
            return ToolResult::fail(
                self::READ_AGENT_ERR_PREFIX
                . '`template_id` is no longer an identifier — use the numeric `agent_id` returned by `create_agent`.',
            );
        }

        $raw = $arguments['agent_id'] ?? null;
        if ($raw === null) {
            $resolvedId = $callingAgentId;
        } elseif (is_int($raw) && $raw > 0) {
            $resolvedId = $raw;
        } elseif (is_numeric($raw)) {
            $n = (int) $raw;
            if ($n <= 0) {
                return ToolResult::fail(
                    self::READ_AGENT_ERR_PREFIX . '`agent_id` must be a positive integer.',
                );
            }
            $resolvedId = $n;
        } else {
            return ToolResult::fail(
                self::READ_AGENT_ERR_PREFIX . '`agent_id` must be a positive integer.',
            );
        }
        $agent = Agent::query()
            ->where('user_id', $userId)
            ->where('id', $resolvedId)
            ->first();
        return $agent
            ?? ToolResult::fail(self::READ_AGENT_ERR_PREFIX . 'agent not found or not owned by this user.');
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
}

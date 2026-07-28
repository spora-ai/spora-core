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
 * Operations that carry an `agent_id` parameter (`read_agent`,
 * `configure_tools`, `update_agent` / its deprecated alias
 * `write_agent_configuration`) accept either the calling agent or any
 * user-owned agent by primary key. `read_agent_configuration` is a
 * legacy soft-redirect to `read_agent(self)`; its `#[ToolOperation]`
 * entry was removed (see {@see self::execute()}). Ownership is always
 * scoped to the authenticated user — cross-user reads are refused, never
 * silently substituted. The LLM-facing agent-creation flow is the
 * two-phase create → configure_tools pattern documented in
 * skills/agent-creation/SKILL.md.
 */
#[Tool(
    name: 'agent',
    description: 'Inspect or modify this agent: read/write its configuration, manage its '
               . 'operator-facing notes, list available tools (with details such as '
               . 'description, source plugin, per-operation enablement and approval state, '
               . 'and any missing required configuration), and create new agents.',
    displayName: 'Agent',
    category: 'agent',
    icon: 'bot',
)]
#[ToolOperation(
    name: 'update_agent',
    description: 'Update editable fields on this agent (name, description, system_prompt, max_steps, '
               . 'allow_followup, retry_after_minutes, max_retries, is_pinned, is_archived, is_favorite). '
               . 'Notes MUST go through write_notes / write_notes_overwrite — they are stripped from '
               . 'this patch. Unknown keys (llm_driver_config_id, anything else outside the allowlist) '
               . 'are silently dropped at the database layer; call read_agent afterwards '
               . 'to confirm the change took effect. For full allowlist and common pitfalls, read the '
               . 'agent-creation skill (skill action: read, name: agent-creation, filename: SKILL.md). '
               . 'Pass `agent_id` (numeric pk returned by `create_agent` / `list_agents`) to target a '
               . 'specific agent; omit to patch the calling agent. The legacy `write_agent_configuration` '
               . 'name still works as a soft-redirect.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'write_agent_configuration',
    description: 'DEPRECATED — use `update_agent`. Update editable fields on this agent. Alias kept '
               . 'for back-compat with prompts that learned the old name; the `update_agent` '
               . 'description is the canonical one. Hard-remove in a later release. See the '
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
    description: 'List every registered tool as a compact, versioned JSON payload '
               . '(version 2 — `tool_class`, `display_name`, `description`, `plugin_slug`, '
               . 'current enablement, missing required configuration, and per-operation '
               . 'enabled/requires_approval state). Tools that need configuration to '
               . 'become activatable are flagged via `ready_to_enable: false`. '
               . 'Use this to plan a sub-agent via `create_agent`. When planning a sub-agent, '
               . 'also read the agent-creation skill (skill action: read, name: agent-creation).',
    enabledByDefault: false,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'create_agent',
    description: 'Create a new agent from a slim payload: `name` (required, top level), '
               . '`description`, `system_prompt`, `max_steps`, `allow_followup`, '
               . '`retry_after_minutes`, `max_retries`. Do NOT wrap fields in an `agent{}` '
               . 'block; do NOT send a `tools[]` block here — after the agent row exists, '
               . 'call `configure_tools(agent_id: N, tools: [...])` to apply a toolset, '
               . 'then `read_agent(agent_id: N)` to verify. The full '
               . 'agent-template schema (id/version/agent{}/tools[]/required_plugins) is '
               . 'reserved for the operator-upload endpoint at '
               . 'POST /api/v1/agent-templates/import and will be rejected on this surface. '
               . 'Read the agent-creation skill first (skill action: read, name: agent-creation, '
               . 'filename: SKILL.md).',
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
               . 'follow-up `read_agent` call. See the agent-creation skill '
               . '(skill action: read, name: agent-creation, filename: SKILL.md) '
               . 'for the slim two-phase flow.',
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
               . '`read_agent_configuration` operation). For the slim two-phase agent-creation '
               . 'flow, see the agent-creation skill (skill action: read, name: agent-creation, '
               . 'filename: SKILL.md).',
    enabledByDefault: false,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'list_agents',
    description: 'List every agent owned by the current user as a slim payload '
               . '(`agent_id`, `name`, `description`). Useful as a discovery surface '
               . 'before `read_agent(agent_id: N)` or `configure_tools(agent_id: N, '
               . 'tools: [...])` — `create_agent` returns an `agent_id` you need for '
               . 'follow-up operations, and this is the cheapest way to recover it after '
               . 'a turn boundary. Archived and pinned state is preserved (call '
               . '`read_agent` for the full manifest). Empty when the user has no agents. '
               . 'See the agent-creation skill (skill action: read, name: agent-creation, '
               . 'filename: SKILL.md).',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(
    name: 'agent',
    type: 'object',
    description: 'ONLY for `update_agent` (and its deprecated alias `write_agent_configuration`): '
              . 'a partial agent with the fields to update. Allowed keys: name, description, '
              . 'system_prompt, max_steps, allow_followup, retry_after_minutes, max_retries, '
              . 'is_pinned, is_archived, is_favorite. `notes` is intentionally not accepted here '
              . '— use write_notes. Ignored by every other operation; omit this key entirely '
              . 'when calling read_notes, write_notes, write_notes_overwrite, get_available_tools, '
              . 'configure_tools, read_agent, list_agents, or create_agent.',
    required: ['update_agent', 'write_agent_configuration'],
)]
#[ToolParameter(
    name: 'content',
    type: 'string',
    description: 'ONLY for write_notes and write_notes_overwrite: the markdown segment to '
              . 'write. Combined with `mode` against the current notes. Ignored by every '
              . 'other operation; omit this key entirely when calling read_notes, '
              . 'update_agent, get_available_tools, or create_agent.',
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
              . '`allow_followup`, `retry_after_minutes`, `max_retries`. '
              . 'Do NOT nest fields under `agent{}` and do NOT send `tools[]` or '
              . '`required_plugins` — the LLM-facing flow is two-phase '
              . '(create_agent → configure_tools(agent_id: N) → '
              . 'read_agent(agent_id: N)). The full Agent Template shape '
              . '(with id/version/agent{}/tools[]/required_plugins) is reserved '
              . 'for the operator-upload endpoint at '
              . 'POST /api/v1/agent-templates/import. '
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
              . 'read_notes, write_notes, write_notes_overwrite, update_agent, '
              . 'get_available_tools, create_agent, list_agents, or read_agent.',
    required: ['configure_tools'],
)]
#[ToolParameter(
    name: 'agent_id',
    type: 'integer',
    description: 'Optional target for read_agent, configure_tools, update_agent. '
              . 'For read_agent: numeric primary key returned by `create_agent`; omit to read the calling agent. '
              . 'For configure_tools: numeric primary key of the agent to configure (default: the calling agent). '
              . 'For update_agent: numeric primary key of the agent to patch (default: the calling agent); '
              . 'use this to edit the name / description / system_prompt of any agent you own. '
              . 'Pass the value from `create_agent` (or `list_agents`) to target a freshly-created agent. '
              . 'Cross-user agent ids return "agent not found or not owned by this user". '
              . 'Ignored by every other operation.',
    required: false,
)]
final class AgentTool extends AbstractTool
{
    private const NOTES_SEPARATOR = "\n\n";

    private const APPEND_MODES = ['append', 'prepend'];

    // Per-operation error prefixes — kept centralised so consumers
    // can locate every fail site with a single grep and so the
    // SonarCloud S1192 "duplicate literal" rule stays green.
    private const AGENT_NOT_FOUND = 'Agent not found.';
    private const CONFIGURE_TOOLS_ERR_PREFIX = 'configure_tools: ';
    private const READ_AGENT_ERR_PREFIX = 'read_agent: ';
    private const WRITE_AGENT_ERR_PREFIX = 'update_agent: ';

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

        if ($operation === 'read_agent_configuration') {
            return $this->redirectReadAgentConfiguration($agentId, $userId);
        }
        if ($operation === 'write_agent_configuration') {
            return $this->redirectWriteAgentConfiguration($agentId, $userId, $arguments);
        }

        return match ($operation) {
            'update_agent'              => $this->writeConfiguration($agentId, $userId, $arguments),
            'read_notes'                => $this->readNotes($agentId),
            'write_notes'               => $this->writeNotes($agentId, $arguments, 'append'),
            'write_notes_overwrite'     => $this->writeNotes($agentId, $arguments, 'overwrite'),
            'get_available_tools'       => $this->getAvailableTools($agentId, $userId),
            'create_agent'              => $this->createAgent($agentId, $arguments),
            'configure_tools'           => $this->configureTools($agentId, $userId, $arguments),
            'read_agent'                => $this->readAgent($agentId, $userId, $arguments),
            'list_agents'               => $this->listAgents($agentId),
            default                     => ToolResult::fail("Invalid action '{$operation}'."),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = (string) ($arguments['action'] ?? $this->getOperationName($arguments));

        return match ($operation) {
            'read_agent_configuration'  => 'Read this agent\'s configuration.',
            'update_agent'              => sprintf(
                'Update agent fields (%s).',
                isset($arguments['agent_id']) && is_scalar($arguments['agent_id']) && (int) $arguments['agent_id'] > 0
                    ? 'agent_id: ' . (int) $arguments['agent_id']
                    : 'calling agent',
            ),
            'write_agent_configuration' => 'Update editable fields on this agent (deprecated — use update_agent).',
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
            'list_agents'               => 'List every agent owned by the current user.',
            default                     => "Agent tool: {$operation}",
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function writeConfiguration(int $callingAgentId, ?int $userId, array $arguments): ToolResult
    {
        $targetId = $this->resolveWriteConfigurationTargetId(
            $userId ?? 0,
            $callingAgentId,
            $arguments,
        );
        if ($targetId instanceof ToolResult) {
            return $targetId;
        }

        $patch = (array) ($arguments['agent'] ?? []);
        // `notes` is intentionally not writable through this surface —
        // it goes through write_notes / write_notes_overwrite. Distinguish
        // "no agent object" from "agent object carried only notes" so the
        // LLM knows which retry to send.
        $hadOnlyNotes = array_keys($patch) === ['notes'];
        unset($patch['notes']);

        if ($patch === []) {
            return ToolResult::fail(
                $hadOnlyNotes
                    ? 'write_agent_configuration: no editable fields after `notes` was stripped. Use write_notes to mutate notes.'
                    : 'write_agent_configuration: agent object is required.',
            );
        }

        $agent = $this->agentService->updateAgentByAgentId($targetId, $patch);
        if ($agent === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }

        return $this->renderManifestResult($agent);
    }

    /**
     * Build a {@see ToolResult} carrying the canonical manifest as
     * `result_data` and the Markdown wrapper as `result_content`. Used
     * by every read/write path that has an Agent row in hand.
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

        // Empty content on append/prepend is a no-op so repeated LLM
        // calls don't pile up separators or drift `updated_at`.
        $isNoop = $combined === $existing;
        if (!$isNoop) {
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
     * @param array<string, mixed> $arguments
     * @return array{0: string, 1: string}|ToolResult
     */
    private function parseWriteNotesArgs(array $arguments, string $defaultMode): array|ToolResult
    {
        if (!array_key_exists('content', $arguments)) {
            return ToolResult::fail('write_notes: content is required.');
        }
        $content = (string) $arguments['content'];
        // `write_notes` accepts the LLM's mode arg (`append`/`prepend`).
        // `write_notes_overwrite` ignores it — the mode is fixed at the
        // call site so the operator-approved destructive path can't be
        // silently downgraded to append.
        if ($defaultMode === 'append' || $defaultMode === 'prepend') {
            $requested = (string) ($arguments['mode'] ?? $defaultMode);
            if (!in_array($requested, self::APPEND_MODES, true)) {
                return ToolResult::fail(
                    "write_notes: invalid mode '{$requested}'. Allowed: " . implode(', ', self::APPEND_MODES) . '.',
                );
            }
            $mode = $requested;
        } else {
            $mode = $defaultMode;
        }
        return [$content, $mode];
    }

    /**
     * Enumerate the calling agent's owner agents as a slim
     * {agent_id, name, description}[] list. `user_id` is sourced
     * from the calling Agent (not a parameter) so the type system
     * stays fail-closed: the only nullable path is a missing agent
     * row, which is already a system-level error.
     */
    private function listAgents(int $agentId): ToolResult
    {
        $agent = $this->agentService->getAgentByAgentId($agentId);
        if ($agent === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }

        $rows = $this->agentService->getAgentsForUser((int) $agent->user_id);
        $slim = array_map(
            static function (array $a): array {
                return [
                    'agent_id'    => $a['id'] ?? null,
                    'name'        => $a['name'] ?? null,
                    'description' => $a['description'] ?? null,
                ];
            },
            $rows,
        );

        $count = count($slim);
        if ($count === 0) {
            $content = 'No agents visible to the current user.';
        } else {
            $lines = ["{$count} agent(s) visible to the current user:"];
            foreach ($slim as $row) {
                $name = (string) ($row['name'] ?? '(unnamed)');
                $id   = $row['agent_id'] === null ? '#?' : '#' . (int) $row['agent_id'];
                $desc = isset($row['description'])
                    && $row['description'] !== ''
                        ? ' — ' . $row['description']
                        : '';
                $lines[] = "- {$id} {$name}{$desc}";
            }
            $content = implode("\n", $lines);
        }

        return ToolResult::ok($content, ['agents' => $slim]);
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
        // `$enriched` keeps the legacy operator-side shape (used as
        // `result_data` on the persisted tool_calls row) so the
        // operator UI's tool_name / category / icon expectations stay
        // intact. The LLM-facing `$content` carries the slimmer v2
        // shape — see {@see self::buildAgentFacingToolRows()}.
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
        $content = json_encode(
            $agentFacing,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return ToolResult::ok($content, $enriched);
    }

    /**
     * Build the LLM-facing payload for `get_available_tools`. v2 dropped
     * `tool_name`, `call_name`, `category`, `icon`, and `source` — each
     * is redundant for the LLM (overlaps with `tool_class`, operator-UI
     * concerns, etc.). The LLM only needs the FQCN (for
     * `configure_tools`), the current enabled state, the missing-config
     * sentinel, and the per-operation details.
     *
     * Precedence rules:
     *   - `tool_class` is the FQCN the LLM passes to `configure_tools`.
     *     Use `plugin_slug` (or null for core) for `required_plugins[]`.
     *   - `enabled` reflects current `agent_tools` row presence.
     *   - `ready_to_enable` mirrors `can_enable` (no missing required
     *     settings). A tool may be enabled with missing required settings
     *     — the server inserts the row and returns a warning, but it is
     *     NOT activatable as `enabled` on the LLM's side without config.
     *   - `missing_required` lists only the required setting keys; no
     *     effective values are exposed to avoid leaking credentials.
     *
     * The default response deliberately OMITS full parameter schemas —
     * the LLM can call `configure_tools` with `operations: [{name, …}]`
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
     * AgentService-ready data shape or a failure ToolResult.
     *
     * The agent-template.schema.json shape (id, name, version, agent{},
     * required_plugins[]) is reserved for the operator-upload endpoint
     * at POST /api/v1/agent-templates/import — driving the operator
     * flow through an LLM-facing call was the root cause of the
     * task #46 failures (too many nested keys, too easy to put `name`
     * inside `agent{}` or send `required_plugins` as a bare value).
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    private function validateCreateAgentPayload(int $userId, array $arguments): array|ToolResult
    {
        $raw = $arguments['payload'] ?? null;
        $error = match (true) {
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
        $name = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
        if ($name === '' || strlen($name) > 200) {
            return ToolResult::fail(
                'create_agent: `name` is required (1..200 chars). '
                . 'Send `name: "..."` at the top level of the payload, not inside `agent{}`.',
            );
        }
        return [
            'name'                 => $name,
            'description'          => is_string($raw['description'] ?? null) ? $raw['description'] : null,
            'system_prompt'        => is_string($raw['system_prompt'] ?? null) ? $raw['system_prompt'] : null,
            'llm_driver_config_id' => null,
            'max_steps'            => (int) ($raw['max_steps'] ?? 10),
            'allow_followup'       => (bool) ($raw['allow_followup'] ?? true),
            'retry_after_minutes'  => (int) ($raw['retry_after_minutes'] ?? 0),
            'max_retries'          => (int) ($raw['max_retries'] ?? 0),
        ];
    }

    /**
     * Validate the slim payload's option keys. Each rule emits a literal
     * "send X instead" example so the LLM can copy-paste the fix rather
     * than guess.
     *
     * @param mixed $raw
     */
    private function createAgentPayloadErrors(mixed $raw): ?ToolResult
    {
        if (!is_array($raw)) {
            return ToolResult::fail('create_agent: `payload` object is required.');
        }
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
            // `required_plugins` is part of the operator-upload
            // agent-template schema but not the slim payload — the
            // LLM-facing `create_agent` won't store it on the agent
            // row. Plugins install out-of-band via the dashboard.
            $plugins = $this->unwrapSingleItemArray($raw['required_plugins']);
            if (!is_array($plugins) || (!$this->isListOfStrings($plugins) && $plugins !== [])) {
                return ToolResult::fail(
                    'create_agent: `required_plugins` is reserved for the operator-upload endpoint '
                    . '(POST /api/v1/agent-templates/import) and is not stored on agents created via '
                    . 'the LLM-facing slim payload. Install plugins via the dashboard or the '
                    . '`spora plugin install` CLI before creating the agent.',
                );
            }
            return ToolResult::fail(
                'create_agent: `required_plugins` is reserved for the operator-upload endpoint '
                . '(POST /api/v1/agent-templates/import) and is not stored on agents created via '
                . 'the LLM-facing slim payload. Install plugins via the dashboard or the '
                . '`spora plugin install` CLI before creating the agent.',
            );
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
     * Defensive unwrap for the OpenAI assistant tool-call channel, which
     * can serialise a single-element array as `{"item": [...]}` instead
     * of `[...]`. Without this unwrap the LLM sees a confusing "must be
     * an array" error on payloads that clearly were arrays.
     *
     * Fires only on the unambiguous wrap shape: non-list assoc with one
     * key called `item` whose value is itself an array. Anything else
     * (multi-key objects, regular lists, scalars, `{item: "scalar"}`)
     * passes through untouched so legitimate payloads survive.
     *
     * @param  mixed        $value
     * @return mixed
     */
    private function unwrapSingleItemArray(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $keys = array_keys($value);
        if ($keys === ['item'] && is_array($value['item'] ?? null)) {
            return $value['item'];
        }
        return $value;
    }

    /**
     * `user_id` is sourced from the calling Agent's row — async callers
     * don't have a session, and the new agent is owned by the same
     * user as the agent that created it. Match the {@see self::listAgents()}
     * pattern: looking up the calling agent is the only trust anchor.
     */
    private function createAgent(int $callingAgentId, array $arguments): ToolResult
    {
        $callingAgent = $this->agentService->getAgentByAgentId($callingAgentId);
        if ($callingAgent === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }
        $userId = (int) $callingAgent->user_id;

        $data = $this->validateCreateAgentPayload($userId, $arguments);
        if ($data instanceof ToolResult) {
            return $data;
        }

        $agent = $this->agentService->createAgent($userId, $data);
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
     * Two-phase agent-creation complement to {@see self::createAgent()}:
     * the LLM creates the skeletal agent first, then calls
     * `configure_tools` to enable tools and per-operation overrides. This
     * avoids forcing N nested decisions (one per
     * tools[i].operations[j]) inside a single approved call.
     *
     * Routes through `AgentToolSettingsServiceInterface` — the existing
     * operator-side surface — so the LLM-facing path and the
     * operator-facing API share the same enable / override semantics.
     * The result is the canonical agent manifest so the LLM can verify
     * what committed without a follow-up `read_agent` call.
     *
     * @param array<string, mixed> $arguments
     */
    private function configureTools(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        // Validate user + payload shape before the target resolver so
        // malformed inputs surface the schema error without a wasted
        // DB lookup. Order mirrors {@see self::createAgent()}.
        $entries = $arguments['tools'] ?? [];
        $entries = $this->unwrapSingleItemArray($entries);
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
        // Re-read after the apply so the manifest renders with the
        // post-change rows + override state. User-scoped to keep the
        // re-read on the same row the resolver already validated.
        $fresh = Agent::query()
            ->where('user_id', $userId)
            ->where('id', $target->id)
            ->first();
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
     * @param  mixed $entry
     * @return array{tool_class: string, enable: bool, operations: list<array{name: string, enabled: bool, auto_approve: bool}>}|ToolResult
     */
    private function parseConfigureToolEntry(mixed $entry, int $i): array|ToolResult
    {
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
     * Returns the failure message for the two object-shape checks, or
     * null when the entry passes both.
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
     * Empty / missing operations is legal — the operation default then
     * applies.
     *
     * @param  mixed $ops
     * @return list<array{name: string, enabled: bool, auto_approve: bool}>|ToolResult
     */
    private function parseConfigureToolOperations(mixed $ops, int $i): array|ToolResult
    {
        if (!is_array($ops) || $ops === []) {
            return [];
        }
        $ops = $this->unwrapSingleItemArray($ops);
        if (!is_array($ops) || ($ops !== [] && !array_is_list($ops))) {
            return ToolResult::fail(
                self::CONFIGURE_TOOLS_ERR_PREFIX . "operations[{$i}] must be an array of `{name, enabled?, auto_approve?}`.",
            );
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
     * Apply the validated `configure_tools` plan.
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
     * Reads the full state of a specific agent. `agent_id` is optional —
     * omit to read the calling agent. Cross-user reads are refused;
     * see {@see self::resolveAgentToolTarget()} for the three input
     * modes (agent_id / omitted / malformed).
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
     * operation. The legacy name still resolves through `execute()` so a
     * prompt that learned it keeps working until the next release
     * hard-removes it.
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
     * Soft-redirect for the deprecated `write_agent_configuration` name.
     *
     * @param array<string, mixed> $arguments
     */
    private function redirectWriteAgentConfiguration(int $callingAgentId, ?int $userId, array $arguments): ToolResult
    {
        $result = $this->writeConfiguration($callingAgentId, $userId, $arguments);
        if (!$result->success) {
            return $result;
        }
        $content = (string) $result->content;
        $note    = "_(deprecated: write_agent_configuration — use `update_agent`)_\n\n";
        return ToolResult::ok($note . $content, $result->data);
    }

    /**
     * Three input modes drive the resolution:
     *   1. `agent_id` supplied and positive → look up that agent, scoped
     *      to the authenticated user (cross-user reads return "not found").
     *   2. `agent_id` omitted → fall back to the calling agent.
     *   3. `agent_id` malformed (zero / non-numeric) → fail with a clear
     *      validation message so the LLM knows what to retry.
     *
     * `template_id` is refused because templates are creation labels
     * (multiple agents can share one) and can never resolve to a single
     * row.
     *
     * @param  array<string, mixed> $arguments
     * @return Agent|ToolResult
     */
    private function resolveAgentToolTarget(int $userId, int $callingAgentId, array $arguments): Agent|ToolResult
    {
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
     * Concatenate $content with $existing per the chosen mode. The
     * separator is a fixed blank line per product decision — operators
     * see a clean markdown break and the agent cannot choose its own
     * joiner.
     */
    private function combineNotes(string $existing, string $content, string $mode): string
    {
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

    /**
     * Resolve the target agent id for `update_agent` (and the
     * deprecated `write_agent_configuration` alias). The
     * omitted-`agent_id` case resolves directly to the calling agent
     * without a DB lookup — a pre-cancel caller (e.g. an operator-API
     * row not yet bound to a user) still resolves without an extra
     * round-trip. The service-level row check (and
     * `updateAgentByAgentId`'s own null-on-miss) handles a row that
     * doesn't exist for the caller.
     *
     * @param  array<string, mixed> $arguments
     * @return int|ToolResult
     */
    private function resolveWriteConfigurationTargetId(
        int $userId,
        int $callingAgentId,
        array $arguments,
    ): int|ToolResult {
        if (!array_key_exists('agent_id', $arguments)) {
            return $callingAgentId;
        }
        $raw = $arguments['agent_id'];
        if (is_int($raw) && $raw > 0) {
            $resolvedId = $raw;
        } elseif (is_numeric($raw)) {
            $n = (int) $raw;
            if ($n <= 0) {
                return ToolResult::fail(
                    self::WRITE_AGENT_ERR_PREFIX
                    . '`agent_id` must be a positive integer.',
                );
            }
            $resolvedId = $n;
        } else {
            return ToolResult::fail(
                self::WRITE_AGENT_ERR_PREFIX
                . '`agent_id` must be a positive integer.',
            );
        }
        $exists = Agent::query()
            ->where('user_id', $userId)
            ->where('id', $resolvedId)
            ->exists();
        return $exists
            ? $resolvedId
            : ToolResult::fail(
                self::WRITE_AGENT_ERR_PREFIX
                . 'agent not found or not owned by this user.',
            );
    }
}

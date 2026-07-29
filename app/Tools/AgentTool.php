<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Models\Agent;
use Spora\Services\AgentManifest;
use Spora\Services\AgentManifestRenderer;
use Spora\Services\AgentServiceInterface;
use Spora\Tools\AgentTool\CatalogPresenter;
use Spora\Tools\AgentTool\ConfigurePlanner;
use Spora\Tools\AgentTool\NotesHandler;
use Spora\Tools\AgentTool\SlimPayloadValidator;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Lets the agent inspect and modify its own configuration, manage its
 * operator-facing notes, discover the tools it could enable, and create
 * new agents on behalf of the current user.
 *
 * Operations carrying an `agent_id` (`read_agent`, `configure_tools`,
 * `update_agent` and its deprecated alias `write_agent_configuration`)
 * accept either the calling agent or any user-owned agent by primary
 * key. Ownership is always scoped to the authenticated user — cross-
 * user reads are refused, never silently substituted. The LLM-facing
 * agent-creation flow is the two-phase create → configure_tools
 * pattern documented in skills/agent-creation/SKILL.md.
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
               . 'agent-creation skill ' . self::AGENT_CREATION_SKILL_HINT . '. '
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
               . 'agent-creation skill ' . self::AGENT_CREATION_SKILL_HINT . '.',
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
    private const AGENT_NOT_FOUND = 'Agent not found.';

    // Centralised so consumers can locate every fail site with a single grep.
    private const CONFIGURE_TOOLS_ERR_PREFIX = 'configure_tools: ';
    private const READ_AGENT_ERR_PREFIX      = 'read_agent: ';
    private const WRITE_AGENT_ERR_PREFIX     = 'update_agent: ';

    private const AGENT_CREATION_SKILL_HINT      = '(skill action: read, name: agent-creation, filename: SKILL.md)';
    private const AGENT_ID_POSITIVE_INTEGER_MSG  = '`agent_id` must be a positive integer.';

    private readonly NotesHandler $notesHandler;

    private readonly CatalogPresenter $catalogPresenter;

    private readonly ConfigurePlanner $configurePlanner;

    private readonly SlimPayloadValidator $payloadValidator;

    public function __construct(
        private readonly AgentServiceInterface $agentService,
        \Spora\Services\AgentToolSettingsServiceInterface $toolSettings,
        private readonly AgentManifest $manifest,
        ?\Spora\Plugins\PluginLoader $pluginLoader = null,
        ?\Spora\Services\ToolIconResolver $iconResolver = null,
        ?NotesHandler $notesHandler = null,
        ?CatalogPresenter $catalogPresenter = null,
        ?ConfigurePlanner $configurePlanner = null,
        ?SlimPayloadValidator $payloadValidator = null,
    ) {
        $this->notesHandler        = $notesHandler        ?? new NotesHandler($agentService);
        $this->catalogPresenter    = $catalogPresenter    ?? new CatalogPresenter($agentService, $toolSettings, $pluginLoader, $iconResolver);
        $this->configurePlanner    = $configurePlanner    ?? new ConfigurePlanner($toolSettings);
        $this->payloadValidator    = $payloadValidator    ?? new SlimPayloadValidator();
    }

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
            'read_notes'                => $this->notesHandler->read($agentId),
            'write_notes'               => $this->notesHandler->write($agentId, $arguments, 'append'),
            'write_notes_overwrite'     => $this->notesHandler->write($agentId, $arguments, 'overwrite'),
            'get_available_tools'       => $this->catalogPresenter->present($agentId, $userId),
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
        $patch = self::buildWriteConfigurationPatch($arguments);
        if ($patch === null) {
            return $this->notesOnlyPatchFail($arguments);
        }
        $agent = $this->agentService->updateAgentByAgentId($targetId, $patch);
        return $agent === null
            ? ToolResult::fail(self::AGENT_NOT_FOUND)
            : $this->renderManifestResult($agent);
    }

    private function notesOnlyPatchFail(array $arguments): ToolResult
    {
        // `notes` is intentionally not writable through this surface — it
        // goes through write_notes / write_notes_overwrite. The "only
        // notes" distinction tells the LLM which retry to send.
        $patch = (array) ($arguments['agent'] ?? []);
        $hadOnlyNotes = array_keys($patch) === ['notes'];
        $message = $hadOnlyNotes
            ? 'no editable fields after `notes` was stripped. Use write_notes to mutate notes.'
            : 'agent object is required.';
        return ToolResult::fail(self::WRITE_AGENT_ERR_PREFIX . $message);
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    private static function buildWriteConfigurationPatch(array $arguments): ?array
    {
        $patch = (array) ($arguments['agent'] ?? []);
        unset($patch['notes']);
        return $patch === [] ? null : $patch;
    }

    private function renderManifestResult(Agent $agent): ToolResult
    {
        $manifest = $this->manifest->toArray($agent);
        return ToolResult::ok(AgentManifestRenderer::markdown($manifest), $manifest);
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

        $content = $slim === []
            ? 'No agents visible to the current user.'
            : $this->renderAgentsList($slim);

        return ToolResult::ok($content, ['agents' => $slim]);
    }

    /**
     * @param  list<array{agent_id: int|string|null, name: string|null, description: string|null}> $slim
     */
    private function renderAgentsList(array $slim): string
    {
        $lines = [count($slim) . ' agent(s) visible to the current user:'];
        foreach ($slim as $row) {
            $name = (string) ($row['name'] ?? '(unnamed)');
            $id   = $row['agent_id'] === null ? '#?' : '#' . (int) $row['agent_id'];
            $desc = isset($row['description']) && $row['description'] !== ''
                ? ' — ' . $row['description']
                : '';
            $lines[] = "- {$id} {$name}{$desc}";
        }
        return implode("\n", $lines);
    }

    /**
     * `user_id` is sourced from the calling Agent's row — async callers
     * don't have a session, and the new agent is owned by the same
     * user as the agent that created it. Match the {@see self::listAgents()}
     * pattern: looking up the calling agent is the only trust anchor.
     *
     * @param array<string, mixed> $arguments
     */
    private function createAgent(int $callingAgentId, array $arguments): ToolResult
    {
        $callingAgent = $this->agentService->getAgentByAgentId($callingAgentId);
        if ($callingAgent === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }

        $data = $this->payloadValidator->validateCreateAgentPayload($arguments);
        if ($data instanceof ToolResult) {
            return $data;
        }

        $agent = $this->agentService->createAgent((int) $callingAgent->user_id, $data);
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
     * Two-phase complement to {@see self::createAgent()}: the LLM creates
     * the skeletal agent first, then calls `configure_tools` to enable
     * tools and per-operation overrides — avoids forcing N nested
     * decisions (one per `tools[i].operations[j]`) inside a single
     * approved call.
     *
     * @param array<string, mixed> $arguments
     */
    private function configureTools(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        if ($userId === null) {
            return ToolResult::fail('configure_tools requires an authenticated user.');
        }

        $entries = SlimPayloadValidator::unwrapSingleItemArray($arguments['tools'] ?? []);
        if (!is_array($entries) || ($entries !== [] && !array_is_list($entries))) {
            return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . '`tools` must be an array.');
        }

        $plan = $this->configurePlanner->buildPlan($entries);
        if ($plan instanceof ToolResult) {
            return $plan;
        }

        $target = $this->resolveAgentToolTarget($userId, $agentId, $arguments);
        if ($target instanceof ToolResult) {
            return $target;
        }
        $this->configurePlanner->apply($target->id, $userId, $plan);

        return $this->renderFreshAgentAfterConfigure($userId, $target->id);
    }

    private function renderFreshAgentAfterConfigure(int $userId, int $agentId): ToolResult
    {
        // User-scoped re-read so the manifest sees the same row the
        // resolver already validated.
        $fresh = Agent::query()
            ->where('user_id', $userId)
            ->where('id', $agentId)
            ->first();
        if ($fresh === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }
        return $this->renderManifestResult($fresh);
    }

    /**
     * Cross-user reads are refused; omitted `agent_id` resolves to the
     * calling agent.
     *
     * @param array<string, mixed> $arguments
     */
    private function readAgent(int $callingAgentId, ?int $userId, array $arguments): ToolResult
    {
        if ($userId === null) {
            return ToolResult::fail('read_agent requires an authenticated user.');
        }
        $target = $this->resolveAgentToolTarget($userId, $callingAgentId, $arguments);
        return $target instanceof ToolResult
            ? $target
            : $this->renderManifestResult($target);
    }

    /**
     * Soft-redirect for the deprecated `read_agent_configuration` name;
     * hard-remove in a later release.
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
     * Three input modes:
     *   1. `agent_id` positive → user-scoped lookup (cross-user → not found)
     *   2. omitted → calling agent
     *   3. malformed (zero / non-numeric) → validation failure
     *
     * `template_id` is refused: templates are creation labels, not row
     * identifiers (multiple agents can share one).
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

        $resolvedId = $this->resolvePositiveAgentId($arguments, $callingAgentId);
        if ($resolvedId instanceof ToolResult) {
            return $resolvedId;
        }

        $agent = Agent::query()
            ->where('user_id', $userId)
            ->where('id', $resolvedId)
            ->first();
        return $agent
            ?? ToolResult::fail(self::READ_AGENT_ERR_PREFIX . 'agent not found or not owned by this user.');
    }

    private function resolvePositiveAgentId(array $arguments, int $callingAgentId): int|ToolResult
    {
        $raw = $arguments['agent_id'] ?? null;
        if ($raw === null) {
            return $callingAgentId;
        }
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (!is_numeric($raw)) {
            return ToolResult::fail(self::READ_AGENT_ERR_PREFIX . self::AGENT_ID_POSITIVE_INTEGER_MSG);
        }
        $n = (int) $raw;
        return $n > 0
            ? $n
            : ToolResult::fail(self::READ_AGENT_ERR_PREFIX . self::AGENT_ID_POSITIVE_INTEGER_MSG);
    }

    /**
     * Omitted `agent_id` resolves to the calling agent without a DB
     * lookup — a pre-cancel caller (e.g. an operator-API row not yet
     * bound to a user) still resolves without an extra round-trip.
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
                return ToolResult::fail(self::WRITE_AGENT_ERR_PREFIX . self::AGENT_ID_POSITIVE_INTEGER_MSG);
            }
            $resolvedId = $n;
        } else {
            return ToolResult::fail(self::WRITE_AGENT_ERR_PREFIX . self::AGENT_ID_POSITIVE_INTEGER_MSG);
        }

        return Agent::query()
            ->where('user_id', $userId)
            ->where('id', $resolvedId)
            ->exists()
                ? $resolvedId
                : ToolResult::fail(self::WRITE_AGENT_ERR_PREFIX . 'agent not found or not owned by this user.');
    }
}

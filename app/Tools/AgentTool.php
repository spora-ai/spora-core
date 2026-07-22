<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\AgentTemplates\ValidationResult;
use Spora\Models\Agent;
use Spora\Services\AgentResource;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Lets the agent inspect and modify its own configuration, manage its
 * operator-facing notes, discover the tools it could enable, and create
 * new agents on behalf of the current user.
 *
 * All operations scope to the calling agent (`$agentId` from
 * `Orchestrator::safeExecute()`); the tool never accepts an `agent_id`
 * argument, so an agent cannot rewrite a sibling. `create_agent` reuses
 * {@see AgentTemplateImporter::importPayload()} so the LLM path and the
 * operator upload endpoint share validation, warnings, and tool-activation
 * semantics.
 *
 * Operations:
 *   - read_agent_configuration  (enabled, no approval)
 *   - write_agent_configuration (disabled, requires approval)
 *   - read_notes                (enabled, no approval)
 *   - write_notes               (enabled, no approval; default mode=append)
 *   - get_available_tools       (disabled, no approval)
 *   - create_agent              (disabled, requires approval)
 */
#[Tool(
    name: 'agent',
    description: 'Inspect or modify this agent: read/write its configuration, manage its '
               . 'operator-facing notes, list available tools (with notes about which need '
               . 'configuration), and create new agents. All operations scope to the calling '
               . 'agent — the tool never accepts an agent_id argument.',
    displayName: 'Agent',
    category: 'agent',
    icon: 'bot',
)]
#[ToolOperation(
    name: 'read_agent_configuration',
    description: 'Read the full configuration of the calling agent (name, description, '
               . 'system prompt, notes, max steps, continuation, retry, pin/archive/favorite, '
               . 'enabled tools).',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'write_agent_configuration',
    description: 'Update editable fields on the calling agent (name, description, system '
               . 'prompt, max steps, continuation, retry, pin/archive/favorite). Notes are '
               . 'managed through read_notes/write_notes, not this operation.',
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
    description: 'Write markdown notes on the calling agent. `mode` defaults to `append` '
               . '(safe), with `prepend` and `overwrite` (destructive) as opt-ins. Segments '
               . 'are joined with a blank line.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'get_available_tools',
    description: 'List every registered tool with whether it can be enabled right now '
               . '(tools needing configuration that has not been set up are flagged). Use '
               . 'to plan tool activation or to build a sub-agent.',
    enabledByDefault: false,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'create_agent',
    description: 'Create a new agent owned by the current user from an Agent Template-shaped '
               . 'payload (id, name, version, agent{}, tools[], required_plugins[]). Tools '
               . 'are activated with default settings when their plugin is loaded; tools '
               . 'missing a plugin or required configuration produce warnings, not errors.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolParameter(
    name: 'agent',
    type: 'object',
    description: 'For write_agent_configuration: a partial agent with the fields to update. '
              . 'Allowed keys: name, description, system_prompt, max_steps, allow_followup, '
              . 'retry_after_minutes, max_retries, is_pinned, is_archived, is_favorite. '
              . '`notes` is intentionally not accepted here — use write_notes.',
    required: false,
)]
#[ToolParameter(
    name: 'content',
    type: 'string',
    description: 'For write_notes: the markdown segment to write. Combined with `mode` '
              . 'against the current notes.',
    required: false,
)]
#[ToolParameter(
    name: 'mode',
    type: 'string',
    description: 'For write_notes: how to combine `content` with the existing notes. '
              . '`append` (default, safe) keeps existing notes and adds new content; '
              . '`prepend` puts new content before; `overwrite` replaces everything '
              . '(destructive — opt-in).',
    required: false,
    enum: ['append', 'prepend', 'overwrite'],
    default: 'append',
)]
#[ToolParameter(
    name: 'payload',
    type: 'object',
    description: 'For create_agent: an Agent Template payload — same shape as the operator '
              . 'upload endpoint. Required plugins are NOT auto-installed; missing plugins '
              . 'produce warnings rather than aborting the import.',
    required: false,
)]
final class AgentTool extends AbstractTool
{
    private const NOTES_MODES = ['append', 'prepend', 'overwrite'];

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
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'read_agent_configuration'  => $this->readConfiguration($agentId),
            'write_agent_configuration' => $this->writeConfiguration($agentId, $arguments),
            'read_notes'                => $this->readNotes($agentId),
            'write_notes'               => $this->writeNotes($agentId, $arguments),
            'get_available_tools'       => $this->getAvailableTools($agentId, $userId),
            'create_agent'              => $this->createAgent($userId, $arguments),
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
            'get_available_tools'       => 'List available tools with configuration status.',
            'create_agent'              => 'Create a new agent from the provided template payload.',
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
        if ($patch === []) {
            return ToolResult::fail('write_agent_configuration: agent object is required.');
        }

        // Strip `notes` defensively — write_agent_configuration must never
        // mutate notes; that goes through write_notes only.
        unset($patch['notes']);

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
     * @param array<string, mixed> $arguments
     * @return array{0: string, 1: string}|ToolResult
     *   Returns [content, mode] on success; a ToolResult to surface
     *   the failure to the caller on a missing/invalid argument.
     */
    private function parseWriteNotesArgs(array $arguments): array|ToolResult
    {
        if (!array_key_exists('content', $arguments)) {
            return ToolResult::fail('write_notes: content is required.');
        }
        $content = (string) $arguments['content'];
        $mode    = (string) ($arguments['mode'] ?? 'append');
        if (!in_array($mode, self::NOTES_MODES, true)) {
            return ToolResult::fail(
                "write_notes: invalid mode '{$mode}'. Allowed: " . implode(', ', self::NOTES_MODES) . '.',
            );
        }
        return [$content, $mode];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function writeNotes(int $agentId, array $arguments): ToolResult
    {
        $parsed = $this->parseWriteNotesArgs($arguments);
        if ($parsed instanceof ToolResult) {
            return $parsed;
        }
        [$content, $mode] = $parsed;

        $agent = $this->agentService->getAgentByAgentId($agentId);
        if ($agent === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }

        $existing = (string) ($agent->notes ?? '');
        $combined = $this->combineNotes($existing, $content, $mode);

        // Route through the service so the same EDITABLE_AGENT_FIELDS
        // allowlist applies as everywhere else; no user-ownership check
        // because the orchestrator has pinned the agent id.
        $this->agentService->updateAgentByAgentId($agentId, ['notes' => $combined]);

        return ToolResult::ok(
            "Notes updated via {$mode} ({$this->humanBytes(mb_strlen($combined))}).",
            [
                'notes'  => $combined,
                'length' => mb_strlen($combined),
                'mode'   => $mode,
            ],
        );
    }

    private function getAvailableTools(int $agentId, ?int $userId): ToolResult
    {
        $agent = $this->agentService->getAgentByAgentId($agentId);
        if ($agent === null) {
            return ToolResult::fail(self::AGENT_NOT_FOUND);
        }

        $userId ??= $agent->user_id;

        $rows = $this->toolSettings->getAllToolsStatus($agentId, $userId) ?? [];
        $enriched = [];
        foreach ($rows as $row) {
            $toolClass = (string) $row['tool_class'];
            $summary   = ToolSchemaPresenter::summarize($toolClass);
            $enriched[] = [
                'tool_class'         => $toolClass,
                'tool_name'          => $summary['tool_name'],
                'display_name'       => $summary['display_name'],
                'category'           => $summary['category'],
                'icon'               => $summary['icon'],
                'is_enabled'         => (bool) $row['is_enabled'],
                'needs_configuration' => $row['can_enable'] === false,
                'missing_required'   => $row['missing_required'],
            ];
        }

        return ToolResult::ok(
            sprintf('Found %d registered tool(s).', count($enriched)),
            $enriched,
        );
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
        // Consolidate the three independent failure paths into a single
        // return so the S1142 3-return ceiling stays satisfied. Each guard
        // contributes one early return via the match below.
        $error = match (true) {
            $userId === null
                => 'create_agent requires an authenticated user.',
            $payload === []
                => 'create_agent: payload object is required.',
            !$validation->isValid()
                => 'create_agent: payload failed validation: '
                   . $this->summarizeValidationErrors($validation),
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
     * Concatenate $content with $existing per the chosen mode. The separator
     * is a fixed blank line per product decision — operators see a clean
     * markdown break between segments and the agent does not get to choose
     * a custom joiner.
     */
    private function combineNotes(string $existing, string $content, string $mode): string
    {
        if ($existing === '') {
            return $content;
        }

        return match ($mode) {
            'append'    => $existing . self::NOTES_SEPARATOR . $content,
            'prepend'   => $content . self::NOTES_SEPARATOR . $existing,
            'overwrite' => $content,
            default     => $content,
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

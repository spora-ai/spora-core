<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Models\Agent;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * User-scoped Agent-row lookups for the `read_agent`,
 * `update_agent`, and `configure_tools` paths.
 *
 * Two input modes cover every operation:
 *   1. positive `agent_id` → user-scoped lookup (cross-user → not found)
 *   2. omitted            → calling agent (no DB query)
 *
 * Malformed ids (zero, non-numeric) fail with the same error prefix
 * the operation that surfaced them would have used, so the LLM sees
 * one consistent message across `read_agent` and `update_agent`.
 */
final class AgentTargetResolver
{
    private const READ_AGENT_ERR_PREFIX      = 'read_agent: ';
    private const WRITE_AGENT_ERR_PREFIX     = 'update_agent: ';
    private const AGENT_ID_POSITIVE_INTEGER_MSG  = '`agent_id` must be a positive integer.';

    private readonly PrincipalService $principalService;

    public function __construct(?PrincipalService $principalService = null)
    {
        $this->principalService = $principalService ?? new PrincipalService(new PrincipalResolver());
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
     * Migration 0067 cut `agents.user_id`. We accept the agent if the
     * caller controls the agent's principal (own user-principal or a
     * group-principal of which they are owner/admin).
     *
     * @param  array<string, mixed> $arguments
     * @return Agent|ToolResult
     */
    public function resolveAgentToolTarget(int $userId, int $callingAgentId, array $arguments): Agent|ToolResult
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

        $agent = Agent::query()->where('id', $resolvedId)->first();
        if ($agent === null) {
            return ToolResult::fail(self::READ_AGENT_ERR_PREFIX . 'agent not found or not owned by this user.');
        }
        if (!$this->principalService->callerControlsPrincipal($userId, (int) $agent->principal_id)) {
            return ToolResult::fail(self::READ_AGENT_ERR_PREFIX . 'agent not found or not owned by this user.');
        }
        return $agent;
    }

    public function resolvePositiveAgentId(array $arguments, int $callingAgentId): int|ToolResult
    {
        $raw = $arguments['agent_id'] ?? null;
        if ($raw === null) {
            return $callingAgentId;
        }

        return $this->parsePositiveAgentId($raw);
    }

    private function parsePositiveAgentId(mixed $raw): int|ToolResult
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (!is_numeric($raw)) {
            return ToolResult::fail(self::READ_AGENT_ERR_PREFIX . self::AGENT_ID_POSITIVE_INTEGER_MSG);
        }
        $n = (int) $raw;
        return $n > 0 ? $n : ToolResult::fail(self::READ_AGENT_ERR_PREFIX . self::AGENT_ID_POSITIVE_INTEGER_MSG);
    }

    /**
     * Omitted `agent_id` resolves to the calling agent without a DB
     * lookup — a pre-cancel caller (e.g. an operator-API row not yet
     * bound to a user) still resolves without an extra round-trip.
     *
     * @param  array<string, mixed> $arguments
     * @return int|ToolResult
     */
    public function resolveWriteConfigurationTargetId(
        int $userId,
        int $callingAgentId,
        array $arguments,
    ): int|ToolResult {
        if (!array_key_exists('agent_id', $arguments)) {
            return $callingAgentId;
        }

        return $this->parseAndValidateWriteConfigurationTargetId($userId, $arguments['agent_id']);
    }

    private function parseAndValidateWriteConfigurationTargetId(int $userId, mixed $raw): int|ToolResult
    {
        if (is_int($raw) && $raw > 0) {
            return $this->ensureAgentOwnedByUser($userId, $raw);
        }
        if (!is_numeric($raw)) {
            return ToolResult::fail(self::WRITE_AGENT_ERR_PREFIX . self::AGENT_ID_POSITIVE_INTEGER_MSG);
        }
        $n = (int) $raw;
        return $n > 0
            ? $this->ensureAgentOwnedByUser($userId, $n)
            : ToolResult::fail(self::WRITE_AGENT_ERR_PREFIX . self::AGENT_ID_POSITIVE_INTEGER_MSG);
    }

    private function ensureAgentOwnedByUser(int $userId, int $agentId): int|ToolResult
    {
        // Migration 0067: agent ownership is via principal, not user_id.
        $agent = Agent::query()->where('id', $agentId)->first();
        if ($agent === null) {
            return ToolResult::fail(self::WRITE_AGENT_ERR_PREFIX . 'agent not found or not owned by this user.');
        }
        if (!$this->principalService->callerControlsPrincipal($userId, (int) $agent->principal_id)) {
            return ToolResult::fail(self::WRITE_AGENT_ERR_PREFIX . 'agent not found or not owned by this user.');
        }
        return $agentId;
    }
}

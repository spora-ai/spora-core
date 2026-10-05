<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ValueObjects\ToolResult;
use stdClass;

/**
 * Unified tool interface — replaces InputToolInterface and OutputToolInterface.
 *
 * Every tool implements this interface. The per-operation flags on #[ToolOperation]
 * determine whether an operation is enabled and whether it requires approval,
 * rather than the class-level Input/OutputTool distinction.
 *
 * Tools without #[ToolOperation] declarations are treated as single-operation tools
 * with class-level defaults.
 */
interface ToolInterface
{
    /**
     * Execute the tool with the arguments provided by the LLM.
     *
     * MUST NOT throw — all errors must be encoded in the returned ToolResult
     * so the LLM can reason about failures.
     *
     * Read access for ownership context:
     *   - `$userId` (legacy): the agent owner's user id, read from the agent
     *     row. Same value as `$context->ownerUserId` — the paying user, NOT
     *     whoever clicked Send. Kept for plugins that still look up
     *     user-scoped settings or media by user id; prefer `$context`.
     *   - `$context` (preferred): the principal context bundle, for plugins
     *     that need to tell owner from runner. `ownerUserId` drives
     *     credential encryption, settings scope and audit attribution;
     *     `runnerUserId` is who triggered this turn.
     *
     * Note the name collides: `OrchestratorInterface::start()` takes a
     * `$userId` too, and that one is the clicker, not the payer.
     *
     * @param  array<string, mixed>   $arguments  Key-value pairs matching #[ToolParameter] names.
     * @param  int                    $agentId    The agent executing this tool.
     * @param  int|null               $userId     Legacy alias for $context->ownerUserId. Same value.
     * @param  int|null               $taskId     The current tick's task id. Available so chat-level
     *                                            tools (sub_agent, summarize, archive) can reference
     *                                            the source Task without re-querying by user id.
     * @param  PrincipalContext|null  $context    Principal context — owner/runner separation.
     */
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult;

    /**
     * Return a human-readable, markdown-safe description of what this tool WILL DO.
     * Displayed in the approval UI before the user approves or rejects.
     *
     * @param  array<string, mixed> $arguments  Arguments as proposed by the LLM.
     */
    public function describeAction(array $arguments): string;

    /**
     * Return the JSON Schema "parameters" object for the LLM function-calling payload.
     *
     * @return array{
     *   type: "object",
     *   properties: array<string, array{type: string, description: string}>|stdClass,
     *   required: list<string>
     * }
     */
    public function getParametersSchema(): array;
}

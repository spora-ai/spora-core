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
     *   - `$userId` (legacy): the calling user id from `tasks.user_id` — the
     *     "who clicked" semantics. Retained for existing plugins that still
     *     look up user-scoped settings or media by user id.
     *   - `$context` (preferred): the principal context bundle. Plugins that
     *     need to distinguish ownership from runner should read
     *     `PrincipalContext::ownerUserId` (the paying user / group's
     *     owner — drives credential encryption, settings scope, and audit
     *     attribution) and `PrincipalContext::runnerUserId` (the user who
     *     triggered the current task — drives memory write attribution
     *     and Mercure publish targets).
     *
     * @param  array<string, mixed>   $arguments  Key-value pairs matching #[ToolParameter] names.
     * @param  int                    $agentId    The agent executing this tool.
     * @param  int|null               $userId     Legacy user context (from task->user_id).
     * @param  int|null               $taskId     The current tick's task id. Available so chat-level
     *                                            tools (handover, summarize, archive) can reference
     *                                            the source Task without re-querying by user_id.
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

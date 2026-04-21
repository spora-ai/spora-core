<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

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
     * @param  array<string, mixed> $arguments  Key-value pairs matching #[ToolParameter] names.
     */
    public function execute(array $arguments, int $agentId): ToolResult;

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
     *   properties: array<string, array{type: string, description: string}>,
     *   required: list<string>
     * }
     */
    public function getParametersSchema(): array;
}

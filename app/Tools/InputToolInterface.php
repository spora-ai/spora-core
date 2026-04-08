<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Tools\ValueObjects\ToolResult;
use stdClass;

interface InputToolInterface
{
    /**
     * Execute the tool with the named arguments provided by the LLM.
     *
     * MUST NOT throw — all errors must be encoded in the returned ToolResult
     * so the LLM can reason about failures.
     *
     * @param  array<string, mixed> $arguments  Key-value pairs matching #[ToolParameter] names.
     */
    public function execute(array $arguments, int $agentId): ToolResult;

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

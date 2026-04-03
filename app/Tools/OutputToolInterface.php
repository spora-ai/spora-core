<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Tools\ValueObjects\ToolResult;

interface OutputToolInterface
{
    /**
     * Execute the tool ONLY after explicit human approval.
     * Called exclusively by ApprovalResumeHandler — never by the Orchestrator loop.
     *
     * MUST NOT throw — all errors encoded in ToolResult.
     *
     * @param  array<string, mixed> $arguments  Arguments confirmed (or edited) by the human.
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
     * @return array{
     *   type: "object",
     *   properties: array<string, array{type: string, description: string}>,
     *   required: list<string>
     * }
     */
    public function getParametersSchema(): array;
}

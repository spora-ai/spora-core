<?php

declare(strict_types=1);

namespace Spora\Drivers\ValueObjects;

/**
 * A tool invocation as requested by the LLM.
 * Flows between the LLM driver, Orchestrator, and tool_calls DB table.
 */
final readonly class ToolCall
{
    public function __construct(
        /**
         * Provider-issued call ID, e.g. "call_abc123".
         * Required by OpenAI/Anthropic to correlate tool results.
         */
        public string $providerCallId,

        /**
         * The tool name as declared in #[Tool(name:)], e.g. "send_email".
         * NOT the PHP class name.
         */
        public string $toolName,

        /**
         * Arguments the LLM wants to pass. Keys match #[ToolParameter] names.
         * @var array<string, mixed>
         */
        public array $arguments,
    ) {}
}

<?php

declare(strict_types=1);

namespace Spora\Tools\ValueObjects;

/**
 * The normalized result of any tool execution (Input or Output).
 * Always returned — errors are encoded inside so the LLM can reason about failures.
 */
final readonly class ToolResult
{
    public function __construct(
        public bool    $success,

        /**
         * Result injected back into the LLM context window.
         * On success: the data the LLM asked for, or a confirmation message.
         * On failure: a human-readable error the LLM can reason about.
         */
        public string  $content,

        /**
         * Optional structured data stored in tool_calls.result_data for UI/audit.
         * Never sent to the LLM directly.
         *
         * @var array<string, mixed>|null
         */
        public ?array  $data = null,
    ) {}

    /**
     * Build a successful ToolResult with the given content and optional data.
     */
    public static function ok(string $content, ?array $data = null): self
    {
        return new self(true, $content, $data);
    }

    /**
     * Build a failed ToolResult carrying a human-readable error message.
     */
    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}

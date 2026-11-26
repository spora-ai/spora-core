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

        /**
         * Archive assets this result points at.
         *
         * Each is rendered as an image content block on a user turn that
         * follows the tool result — a provider's tool result carries text
         * only, so the image cannot travel inside it. The bytes are read from
         * storage when the request is built; the reference is what gets
         * persisted.
         *
         * @var list<array{media_id: string, kind: string}>
         */
        public array   $attachments = [],
    ) {}

    /**
     * Build a successful ToolResult with the given content and optional data.
     *
     * @param list<array{media_id: string, kind: string}> $attachments
     */
    public static function ok(string $content, ?array $data = null, array $attachments = []): self
    {
        return new self(true, $content, $data, $attachments);
    }

    /**
     * Build a failed ToolResult carrying a human-readable error message.
     */
    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}

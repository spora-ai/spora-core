<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

/**
 * Maps LLM content block `type` strings to the {@see ContentBlockParser}
 * implementation responsible for normalising that block. Unknown types
 * are skipped by the dispatcher.
 */
final class ContentBlockParserRegistry
{
    /**
     * @var array<string, ContentBlockParser>
     */
    private array $parsers;

    public function __construct()
    {
        $this->parsers = [
            'text'              => new TextBlockParser(),
            'thinking'          => new ThinkingBlockParser(),
            'redacted_thinking' => new RedactedThinkingBlockParser(),
        ];
    }

    public function for(string $type): ?ContentBlockParser
    {
        return $this->parsers[$type] ?? null;
    }
}

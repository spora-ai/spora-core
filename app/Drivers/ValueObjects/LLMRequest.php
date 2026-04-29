<?php

declare(strict_types=1);

namespace Spora\Drivers\ValueObjects;

final readonly class LLMRequest
{
    public function __construct(
        /** System prompt derived from the active Recipe. */
        public string $systemPrompt,

        /**
         * Full conversation history in OpenAI-compatible format.
         * @var list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string, name?: string}>
         */
        public array $messages,

        /**
         * Tool definitions in OpenAI function-calling format.
         * @var list<array{type: "function", function: array{name: string, description: string, parameters: array}}>
         */
        public array $tools,

        /** Context window size (total tokens model can handle). Used for message truncation. 0 = no limit. */
        public int $contextWindow = 0,
        public int   $maxTokens   = 4096,
        public float $temperature = 0.7,
    ) {}
}

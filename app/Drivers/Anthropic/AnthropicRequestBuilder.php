<?php

declare(strict_types=1);

namespace Spora\Drivers\Anthropic;

use Spora\Drivers\ValueObjects\ContentBlock;
use Spora\Drivers\ValueObjects\LLMRequest;

/**
 * Serialises an {@see LLMRequest} into the Anthropic `/v1/messages` wire
 * shape. Pure transformation: no I/O, no driver state, no logger — the
 * driver is responsible for the HTTP call.
 *
 * Split out of {@see \Spora\Drivers\AnthropicCompatibleDriver} so the
 * Anthropic-specific rules (cache-control breakpoints, system-prompt
 * array wrap, thinking-budget header) live next to the request shape
 * they govern and the driver class stays focused on transport.
 */
final class AnthropicRequestBuilder
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly bool $enablePromptCaching,
        private readonly ?float $temperature,
        private readonly ?int $thinkingBudget,
    ) {}

    /**
     * @return array<string, string>
     */
    public function buildHeaders(): array
    {
        $headers = [
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ];

        if ($this->apiKey !== '') {
            $headers['x-api-key'] = $this->apiKey;
        }

        return $headers;
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    public function buildBody(LLMRequest $request, array $tools, array $messages): array
    {
        // Skip the cache breakpoint on an empty system prompt — the
        // breakpoint would be wasted on a one-byte auto-wrap and the
        // operator has nothing to cache.
        $system = $request->systemPrompt;
        if ($this->enablePromptCaching && $request->systemPrompt !== '') {
            $system = [[
                'type' => 'text',
                'text' => $request->systemPrompt,
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        }

        $body = [
            'model' => $this->model,
            'system' => $system,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens,
        ];

        if ($tools !== []) {
            if ($this->enablePromptCaching) {
                $lastTool = array_key_last($tools);
                $tools[$lastTool]['cache_control'] = ['type' => 'ephemeral'];
            }
            $body['tools'] = $tools;
        }

        if ($this->temperature !== null) {
            $body['temperature'] = $this->temperature;
        }

        if ($this->thinkingBudget !== null) {
            $body['thinking'] = [
                'type' => 'enabled',
                'budget_tokens' => $this->thinkingBudget,
            ];
        }

        return $body;
    }

    /**
     * Convert OpenAI function-calling tool definitions to Anthropic format.
     *
     * OpenAI:   [{type: "function", function: {name, description, parameters}}]
     * Anthropic: [{name, description, input_schema}]
     *
     * Already-Anthropic-shaped tools (top-level `name` + `input_schema`)
     * pass through unchanged so callers can hand either shape in.
     *
     * @param  list<array<string, mixed>>  $tools
     * @return list<array<string, mixed>>
     */
    /**
     * @param list<array<string, mixed>> $tools
     * @return list<array<string, mixed>>
     */
    public function convertTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            if (($tool['type'] ?? null) === 'function') {
                $fn = is_array($tool['function'] ?? null) ? $tool['function'] : [];
                $out[] = [
                    'name' => (string) ($fn['name'] ?? ''),
                    'description' => (string) ($fn['description'] ?? ''),
                    'input_schema' => is_array($fn['parameters'] ?? null) ? $fn['parameters'] : [],
                ];
                continue;
            }
            // Anything not explicitly typed as `function` is an
            // already-Anthropic-shaped tool definition — pass through
            // unchanged. (Non-function tool types like `not_function`
            // would have been caught by the OpenAI branch above; this
            // fallthrough is the Anthropic-native path.)
            if (!isset($tool['name'], $tool['input_schema'])) {
                continue;
            }
            $out[] = [
                'name' => (string) $tool['name'],
                'description' => (string) ($tool['description'] ?? ''),
                'input_schema' => $tool['input_schema'],
            ];
        }
        return $out;
    }

    /**
     * Convert OpenAI-format messages to Anthropic format.
     *
     * Key conversions:
     * - Assistant messages with `tool_calls` → Anthropic content blocks
     *   `[{type:"tool_use",...}]`.
     * - Tool result messages (`role:"tool"`) → batched into user
     *   messages with `[{type:"tool_result",...}]`. Multiple
     *   consecutive tool results collapse into one user turn.
     *
     * @param  list<array{role: string, content: string|null, tool_calls?: array<string, mixed>, tool_call_id?: string, name?: string}>  $messages
     * @return list<array{role: string, content: string|array<mixed>}>
     */
    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public function convertMessages(array $messages): array
    {
        $out = [];
        $toolResults = [];

        foreach ($messages as $msg) {
            $role = (string) ($msg['role'] ?? '');

            if ($role === 'tool') {
                // Accumulate; will be flushed as a single user turn.
                $toolResults[] = $this->buildToolResultBlock($msg);
                continue;
            }

            if ($role === 'assistant' && isset($msg['tool_calls'])) {
                if ($toolResults !== []) {
                    $out[] = $this->flushToolResults($toolResults);
                    $toolResults = [];
                }
                $out[] = $this->buildAssistantToolUseMessage(
                    is_array($msg['tool_calls']) ? $msg['tool_calls'] : [],
                    $msg['content'] ?? null,
                );
                continue;
            }

            if ($toolResults !== []) {
                $out[] = $this->flushToolResults($toolResults);
                $toolResults = [];
            }

            $out[] = [
                'role' => $role,
                'content' => $this->normalizeMessageContent($msg['content'] ?? null),
            ];
        }

        // Flush any trailing tool results
        if ($toolResults !== []) {
            $out[] = $this->flushToolResults($toolResults);
        }

        return $out;
    }

    /**
     * @return string|list<array<string, mixed>>
     */
    private function normalizeMessageContent(mixed $content): string|array
    {
        if ($content === null) {
            return '';
        }
        if (!is_array($content)) {
            return (string) $content;
        }
        $blocks = [];
        foreach ($content as $b) {
            if (!is_array($b)) {
                continue;
            }
            $block = $this->contentBlockToAnthropic($b);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }
        return $blocks === [] ? '' : $blocks;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private function contentBlockToAnthropic(array $block): ?array
    {
        $type = $block['type'] ?? null;
        return match ($type) {
            ContentBlock::TYPE_TEXT => ['type' => 'text', 'text' => (string) ($block['text'] ?? '')],
            ContentBlock::TYPE_IMAGE => $this->imageBlockToAnthropic($block),
            ContentBlock::TYPE_THINKING => [
                'type' => 'thinking',
                'thinking' => (string) ($block['text'] ?? ''),
                'signature' => (string) ($block['signature'] ?? ''),
            ],
            ContentBlock::TYPE_REDACTED_THINKING => [
                'type' => 'redacted_thinking',
                'data' => (string) ($block['data'] ?? ''),
            ],
            ContentBlock::TYPE_TOOL_USE => $this->toolUseBlockToAnthropic($block),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function toolUseBlockToAnthropic(array $block): array
    {
        $input = $block['toolInput'] ?? $block['tool_input'] ?? [];
        $input = is_array($input) ? $input : [];
        if (array_is_list($input)) {
            $input = (object) $input;
        }

        return [
            'type' => 'tool_use',
            'id' => (string) ($block['toolUseId'] ?? $block['tool_use_id'] ?? ''),
            'name' => (string) ($block['toolName'] ?? $block['tool_name'] ?? ''),
            'input' => $input,
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private function imageBlockToAnthropic(array $block): ?array
    {
        if (isset($block['base64']) && is_string($block['base64']) && $block['base64'] !== '' && isset($block['mediaType'])) {
            return [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => (string) $block['mediaType'],
                    'data'       => $block['base64'],
                ],
            ];
        }
        if (isset($block['url']) && is_string($block['url']) && $block['url'] !== '') {
            return [
                'type'   => 'image',
                'source' => ['type' => 'url', 'url' => $block['url']],
            ];
        }

        return null;
    }

    /**
     * @param  array{role: string, content: string|null, tool_call_id?: string, name?: string}  $msg
     * @return array{type: string, tool_use_id: string, content: string}
     */
    private function buildToolResultBlock(array $msg): array
    {
        return [
            'type'        => 'tool_result',
            'tool_use_id' => (string) ($msg['tool_call_id'] ?? ''),
            'content'     => (string) ($msg['content'] ?? ''),
        ];
    }

    /**
     * Flush a buffer of accumulated tool-result blocks as a single
     * Anthropic user message. Used by {@see self::convertMessages()} to
     * batch consecutive `role:"tool"` rows into one turn.
     *
     * @param  list<array{type: string, tool_use_id: string, content: string}>  $toolResults
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    private function flushToolResults(array $toolResults): array
    {
        return [
            'role' => 'user',
            'content' => $toolResults,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $toolCalls
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    private function buildAssistantToolUseMessage(array $toolCalls, mixed $content): array
    {
        $contentBlocks = [];
        $existingIds = $this->collectExistingToolUseIds($content);
        foreach ($toolCalls as $tc) {
            $serialized = $this->buildToolUseBlock($tc);
            if (in_array($serialized['id'], $existingIds, true)) {
                continue;
            }
            $contentBlocks[] = $serialized;
        }
        return [
            'role' => 'assistant',
            'content' => $contentBlocks,
        ];
    }

    /**
     * @return list<string>
     */
    private function collectExistingToolUseIds(mixed $content): array
    {
        $ids = [];
        if (!is_array($content)) {
            return $ids;
        }
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                $ids[] = (string) ($block['id'] ?? '');
            }
        }
        return $ids;
    }

    /**
     * Build a single Anthropic `tool_use` content block. Accepts both
     * the OpenAI shape (`{id, type, function: {name, arguments}}` where
     * `arguments` is a JSON-encoded string) and the Anthropic shape
     * (`{id, name, input}`). List-shaped inputs are wrapped as object
     * to satisfy Anthropic's `input` schema requirement.
     *
     * @param  array<string, mixed>  $tc
     * @return array<string, mixed>
     */
    private function buildToolUseBlock(array $tc): array
    {
        $name = (string) ($tc['name'] ?? '');
        $input = $tc['input'] ?? [];

        if (is_array($tc['function'] ?? null)) {
            $fn = $tc['function'];
            $name = (string) ($fn['name'] ?? $name);
            $rawArgs = $fn['arguments'] ?? '{}';
            if (is_string($rawArgs) && $rawArgs !== '') {
                $decoded = json_decode($rawArgs, true);
                if (is_array($decoded)) {
                    $input = $decoded;
                }
            }
        }

        $input = is_array($input) ? $input : [];
        if (array_is_list($input)) {
            $input = (object) $input;
        }

        return [
            'type' => 'tool_use',
            'id' => (string) ($tc['id'] ?? ''),
            'name' => $name,
            'input' => $input,
        ];
    }
}

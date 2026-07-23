<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Psr\Log\LoggerInterface;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Drivers\Utilities\LLMContentParser;
use Spora\Drivers\ValueObjects\ContentBlock;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall;
use Spora\Drivers\ValueObjects\Usage;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[ToolSetting(key: 'api_key', label: 'API Key', type: 'password', description: 'API key for the Anthropic-compatible endpoint. Leave empty for local models.', required: false)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Base URL of the API endpoint (e.g. https://api.anthropic.com).', required: false, default: 'https://api.anthropic.com')]
#[ToolSetting(key: 'model', label: 'Model', type: 'text', description: 'Model identifier (e.g. claude-3-5-sonnet-20241022, claude-3-opus).', required: false, default: 'claude-3-5-sonnet-20241022')]
#[ToolSetting(key: 'max_tokens_output', label: 'Max Output Tokens', type: 'text', description: 'Maximum number of tokens to generate (output buffer).', required: false, default: '4096')]
#[ToolSetting(key: 'temperature', label: 'Temperature', type: 'text', description: 'Sampling temperature (0.0–2.0). Lower is more deterministic.', required: false, default: '0.7', validation: '/^[0-2](\.[0-9]+)?$/')]
#[ToolSetting(key: 'context_window', label: 'Context Window', type: 'text', description: 'Total context window size for this model (input + output combined, e.g. 200000).', required: false, default: '200000')]
#[ToolSetting(key: 'thinking_budget', label: 'Thinking Budget (tokens)', type: 'text', description: 'Maximum tokens for extended thinking (Claude 3.7+).', required: false, validation: '/^[1-9][0-9]*$/')]
#[ToolSetting(key: 'timeout', label: 'Timeout (seconds)', type: 'text', description: 'HTTP timeout per request. Increase for slow models (e.g. local Ollama).', required: false, default: '300')]
final class AnthropicCompatibleDriver extends AbstractCompatibleDriver
{
    private const API_VERSION = '2023-06-01';

    private const PROVIDER_KEY = 'anthropic_compatible';

    private readonly ?float $temperature;

    private readonly ?int $thinkingBudget;

    private readonly bool $enablePromptCaching;

    public function __construct(
        string              $apiKey,
        string              $model,
        string              $baseUrl,
        HttpClientInterface $httpClient,
        ?LoggerInterface    $logger = null,
        ?int                $timeout = null,
        ?AnthropicDriverOptions $options = null,
    ) {
        parent::__construct(
            $apiKey,
            $model,
            $baseUrl,
            $httpClient,
            $logger,
            $timeout,
            $options?->supportsImageInput,
        );
        $this->temperature    = $options?->temperature;
        $this->thinkingBudget = $options?->thinkingBudget;
        $this->enablePromptCaching = $options->enablePromptCaching ?? true;
    }

    /**
     * Claude 3 / 4 family models all accept image content blocks on the
     * Anthropic Messages API. Older models (Claude 2, Claude Instant)
     * did not — keep the allowlist conservative.
     */
    protected function modelBasedSupportsImageInput(): bool
    {
        return str_starts_with($this->model, 'claude-3-')
            || str_starts_with($this->model, 'claude-4-');
    }

    public function getProviderName(): string
    {
        return static::getName();
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $tools    = $this->convertTools($request->tools);
        $messages = $this->convertMessages($request->messages);
        $body     = $this->buildAnthropicRequestBody($request, $tools, $messages);

        $url     = rtrim($this->baseUrl, '/') . '/v1/messages';
        $headers = $this->buildAnthropicHeaders();
        $this->logger?->debug('LLM Request (Anthropic)', ['url' => $url, 'payload' => $body]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $headers,
            'json'    => $body,
            'timeout' => $this->timeout ?? 300,
        ]);

        $this->throwIfErrorResponse($response);

        return $this->parseAnthropicResponse($response);
    }

    /**
     * @param  list<array{name: string, description: string, input_schema: array}>  $tools
     * @param  list<array{role: string, content: string|array}>  $messages
     * @return array<string, mixed>
     */
    private function buildAnthropicRequestBody(LLMRequest $request, array $tools, array $messages): array
    {
        $system = $request->systemPrompt;
        if ($this->enablePromptCaching) {
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
     * @return array<string, string>
     */
    private function buildAnthropicHeaders(): array
    {
        $headers = [
            'anthropic-version' => self::API_VERSION,
            'Content-Type'      => 'application/json',
        ];

        if ($this->apiKey !== '') {
            $headers['x-api-key'] = $this->apiKey;
        }

        return $headers;
    }

    private function throwIfErrorResponse(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode === 429) {
            throw new LLMRateLimitException('Anthropic rate limit exceeded (HTTP 429).');
        }

        if ($statusCode >= 500) {
            $rawBody = $response->getContent(throw: false);
            throw new LLMRetryableException("Anthropic API error {$statusCode}: {$rawBody}");
        }

        if ($statusCode >= 400) {
            $rawBody = $response->getContent(throw: false);
            throw new LLMProviderException("Anthropic API error {$statusCode}: {$rawBody}");
        }
    }

    private function parseAnthropicResponse(ResponseInterface $response): LLMResponse
    {
        $statusCode = $response->getStatusCode();

        /** @var array<string, mixed> $data */
        $data = $response->toArray();
        $this->logger?->debug('LLM Response (Anthropic)', ['status' => $statusCode, 'data' => $data]);

        $completionId = (string) ($data['id'] ?? '');
        $stopReason = (string) ($data['stop_reason'] ?? '');
        $rawBlocks = is_array($data['content'] ?? null) ? $data['content'] : [];
        $parsedContent = LLMContentParser::parse($rawBlocks);
        $usage = $this->buildUsage(is_array($data['usage'] ?? null) ? $data['usage'] : null);

        if ($stopReason !== 'tool_use') {
            return new LLMResponse(
                content: $parsedContent['textContent'],
                toolCalls: [],
                inputTokens: $usage->inputTokens,
                outputTokens: $usage->outputTokens,
                completionId: $completionId,
                contentBlocks: $parsedContent['contentBlocks'],
                usage: $usage,
                displayReasoning: $parsedContent['displayReasoning'],
            );
        }

        return new LLMResponse(
            content: $parsedContent['textContent'] !== '' ? $parsedContent['textContent'] : null,
            toolCalls: $this->extractToolCalls($rawBlocks),
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            completionId: $completionId,
            contentBlocks: $parsedContent['contentBlocks'],
            usage: $usage,
            displayReasoning: $parsedContent['displayReasoning'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $contentBlocks
     * @return list<ToolCall>
     */
    private function extractToolCalls(array $contentBlocks): array
    {
        $toolCalls = [];

        foreach ($contentBlocks as $block) {
            if (($block['type'] ?? '') !== 'tool_use') {
                continue;
            }

            $toolCalls[] = new ToolCall(
                providerCallId: (string) ($block['id'] ?? ''),
                toolName: (string) ($block['name'] ?? ''),
                arguments: (array) ($block['input'] ?? []),
            );
        }

        return $toolCalls;
    }

    /**
     * Convert OpenAI function-calling tool definitions to Anthropic format.
     *
     * OpenAI: [{type: "function", function: {name, description, parameters: {...}}}]
     * Anthropic: [{name, description, input_schema: {...}}]
     *
     * @param  list<array{type: string, function: array{name: string, description: string, parameters: array}}>  $tools
     * @return list<array{name: string, description: string, input_schema: array}>
     */
    private function convertTools(array $tools): array
    {
        $converted = [];

        foreach ($tools as $tool) {
            if ($tool['type'] !== 'function') {
                continue;
            }

            $fn = $tool['function'];

            $converted[] = [
                'name'         => $fn['name'],
                'description'  => $fn['description'],
                'input_schema' => $fn['parameters'],
            ];
        }

        return $converted;
    }

    /**
     * Convert OpenAI-format messages to Anthropic format.
     *
     * Key conversions:
     * - Assistant messages with tool_calls → Anthropic content blocks [{type:"tool_use",...}]
     * - Tool result messages (role:"tool") → Batched into user messages with [{type:"tool_result",...}]
     *   (Multiple consecutive tool results collapse into one user turn.)
     *
     * @param  list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string, name?: string}>  $messages
     * @return list<array{role: string, content: string|array}>
     */
    private function convertMessages(array $messages): array
    {
        $converted   = [];
        $toolResults = [];

        foreach ($messages as $msg) {
            $role = $msg['role'];

            if ($role === 'tool') {
                // Accumulate; will be flushed as a single user turn
                $toolResults[] = $this->buildToolResultBlock($msg);
                continue;
            }

            if ($role === 'assistant' && isset($msg['tool_calls'])) {
                $converted[] = $this->buildAssistantToolUseMessage(
                    $msg['tool_calls'],
                    $msg['content'] ?? null,
                );
                continue;
            }

            if ($toolResults !== []) {
                $converted[] = $this->flushToolResults($toolResults);
                $toolResults = [];
            }

            $converted[] = ['role' => $role, 'content' => $this->normalizeMessageContent($msg['content'] ?? null)];
        }

        // Flush any trailing tool results
        if ($toolResults !== []) {
            $converted[] = $this->flushToolResults($toolResults);
        }

        return $converted;
    }

    /**
     * Translate a message's `content` field into Anthropic's wire shape.
     * Three input forms:
     *   - null / string     → return as-is (text).
     *   - list<ContentBlock> (the new multi-modal shape) → translate to
     *     Anthropic blocks: `{type:"text", text}` for text and
     *     `{type:"image", source:{type, media_type, data|url}}` for
     *     image blocks.
     */
    private function normalizeMessageContent(mixed $content): string|array
    {
        if ($content === null || is_string($content)) {
            return $content ?? '';
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
        if ($type === ContentBlock::TYPE_TEXT) {
            return ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
        }
        if ($type === ContentBlock::TYPE_IMAGE) {
            return $this->imageBlockToAnthropic($block);
        }
        if ($type === ContentBlock::TYPE_THINKING) {
            return [
                'type' => 'thinking',
                'thinking' => (string) ($block['text'] ?? ''),
                'signature' => (string) ($block['signature'] ?? ''),
            ];
        }
        if ($type === ContentBlock::TYPE_REDACTED_THINKING) {
            return ['type' => 'redacted_thinking', 'data' => (string) ($block['data'] ?? '')];
        }
        if ($type === ContentBlock::TYPE_TOOL_USE) {
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

        return null;
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
     * @param  list<array<string, mixed>>  $toolResults
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    private function flushToolResults(array $toolResults): array
    {
        return ['role' => 'user', 'content' => $toolResults];
    }

    /**
     * @param list<array<string, mixed>> $toolCalls
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    private function buildAssistantToolUseMessage(array $toolCalls, mixed $content): array
    {
        $normalized = $this->normalizeMessageContent($content);
        $contentBlocks = is_array($normalized)
            ? $normalized
            : ($normalized === '' ? [] : [['type' => 'text', 'text' => $normalized]]);

        $existingIds = [];
        foreach ($contentBlocks as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                $existingIds[] = (string) ($block['id'] ?? '');
            }
        }

        foreach ($toolCalls as $toolCall) {
            $id = (string) ($toolCall['id'] ?? '');
            if (!in_array($id, $existingIds, true)) {
                $contentBlocks[] = $this->buildToolUseBlock($toolCall);
            }
        }

        return ['role' => 'assistant', 'content' => $contentBlocks];
    }

    /**
     * @param  array<string, mixed>  $tc
     * @return array{type: string, id: string, name: string, input: mixed}
     */
    private function buildToolUseBlock(array $tc): array
    {
        $rawArguments = $tc['function']['arguments'] ?? '{}';
        $input        = is_string($rawArguments)
            ? (json_decode($rawArguments, true) ?? [])
            : (array) $rawArguments;

        // Anthropic requires input to be a dict, not a bare list/array.
        // (object)[] becomes {} in JSON; array_is_list check handles
        // non-empty lists which also must not be sent as bare arrays.
        if (!is_array($input) || array_is_list($input)) {
            $input = (object) $input;
        }

        return [
            'type'  => 'tool_use',
            'id'    => (string) ($tc['id'] ?? ''),
            'name'  => (string) ($tc['function']['name'] ?? ''),
            'input' => $input,
        ];
    }

    /**
     * @param array<string, mixed>|null $usage
     */
    private function buildUsage(?array $usage): Usage
    {
        return Usage::fromProviderUsage($usage, 'anthropic');
    }

    public static function getName(): string
    {
        return self::PROVIDER_KEY;
    }

    public static function getDisplayName(): string
    {
        return 'Anthropic Compatible';
    }
}

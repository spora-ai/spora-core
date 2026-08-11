<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Psr\Log\LoggerInterface;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Drivers\Utilities\LLMContentParser;
use Spora\Drivers\Utilities\ThinkingTagExtractor;
use Spora\Drivers\ValueObjects\ContentBlock;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall;
use Spora\Drivers\ValueObjects\Usage;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[ToolSetting(key: 'api_key', label: 'API Key', type: 'password', description: 'API key for the OpenAI-compatible endpoint. Leave empty for local models.', required: false, )]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Base URL of the API endpoint (e.g. https://api.openai.com/v1).', required: false, default: 'https://api.openai.com/v1')]
#[ToolSetting(key: 'model', label: 'Model', type: 'text', description: 'Model identifier (e.g. gpt-4o, gpt-4-turbo, o1-preview).', required: false, default: 'gpt-4o')]
#[ToolSetting(key: 'temperature', label: 'Temperature', type: 'text', description: 'Sampling temperature (0.0–2.0). Lower is more deterministic.', required: false, default: '0.7', validation: '/^[0-2](\.[0-9]+)?$/')]
#[ToolSetting(key: 'timeout', label: 'Timeout (seconds)', type: 'text', description: 'HTTP timeout per request. Increase for slow models (e.g. local Ollama).', required: false, default: '300')]
final class OpenAICompatibleDriver extends AbstractCompatibleDriver
{
    private const PROVIDER_KEY = 'openai_compatible';

    public function __construct(
        string              $apiKey,
        string              $model,
        string              $baseUrl,
        HttpClientInterface $httpClient,
        ?LoggerInterface    $logger = null,
        ?int                $timeout = null,
        ?bool               $supportsImageInput = null,
    ) {
        parent::__construct($apiKey, $model, $baseUrl, $httpClient, $logger, $timeout, $supportsImageInput);
    }

    public function getProviderName(): string
    {
        return static::getName();
    }

    /**
     * Vision-capable OpenAI-family model names. Conservative allowlist:
     *   - gpt-4o, gpt-4o-mini, gpt-4-turbo, gpt-4-vision*
     *   - o1, o1-pro, o3, o3-mini, o4-mini
     *   - chatgpt-4o*
     *
     * `gpt-3.5*`, `gpt-4` (non-vision), `o1-mini` are explicitly excluded.
     * Custom OpenAI-compatible endpoints (e.g. a private deployment) are
     * treated as text-only unless the operator overrides via the
     * `supports_image_input` setting on the LLM driver config.
     */
    protected function modelBasedSupportsImageInput(): bool
    {
        $m = strtolower($this->model);
        if ($m === '' || $m === 'gpt-3.5-turbo' || $m === 'gpt-4' || $m === 'o1-mini') {
            return false;
        }
        return str_starts_with($m, 'gpt-4o')
            || str_starts_with($m, 'gpt-4-turbo')
            || str_starts_with($m, 'gpt-4-vision')
            || str_starts_with($m, 'o1')
            || str_starts_with($m, 'o3')
            || str_starts_with($m, 'o4')
            || str_starts_with($m, 'chatgpt-4o');
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $messages = $this->buildMessages($request);
        $body = $this->buildRequestBody($request, $messages);
        $url = rtrim($this->baseUrl, '/') . '/chat/completions';
        $this->logger?->debug('LLM Request (OpenAI)', ['url' => $url, 'payload' => $body]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $this->buildHeaders(),
            'json' => $body,
            'timeout' => $this->timeout ?? 300,
        ]);

        $this->throwIfError($response);
        /** @var array<string, mixed> $data */
        $data = $response->toArray();
        $this->logger?->debug('LLM Response (OpenAI)', ['status' => $response->getStatusCode(), 'data' => $data]);
        return $this->parseResponse($data);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildMessages(LLMRequest $request): array
    {
        return array_merge(
            [['role' => 'system', 'content' => $request->systemPrompt]],
            array_map(
                static function (array $msg): array {
                    $content = $msg['content'] ?? null;
                    if ($content === null || is_string($content)) {
                        return $msg;
                    }
                    $msg['content'] = self::normalizeContent($content);
                    return $msg;
                },
                $request->messages,
            ),
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function buildRequestBody(LLMRequest $request, array $messages): array
    {
        $body = [
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $request->maxTokens,
            'temperature' => $request->temperature,
        ];
        if ($request->tools !== []) {
            $body['tools'] = $request->tools;
            $body['tool_choice'] = 'auto';
        }
        return $body;
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        return $headers;
    }

    /**
     * @param \Symfony\Contracts\HttpClient\ResponseInterface $response
     */
    private function throwIfError($response): void
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode === 429) {
            throw new LLMRateLimitException('OpenAI rate limit exceeded (HTTP 429).');
        }
        if ($statusCode >= 500) {
            $body = $response->getContent(throw: false);
            throw new LLMRetryableException("OpenAI API error {$statusCode}: {$body}");
        }
        if ($statusCode >= 400) {
            $body = $response->getContent(throw: false);
            throw new LLMProviderException("OpenAI API error {$statusCode}: {$body}");
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data): LLMResponse
    {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $blocks = $this->resolveMessageBlocks($message);
        $parsedContent = LLMContentParser::parse($blocks);

        if (($choice['finish_reason'] ?? '') === 'tool_calls') {
            return $this->buildToolCallsResponse($data, $message, $parsedContent);
        }

        $usage = $this->buildUsage(is_array($data['usage'] ?? null) ? $data['usage'] : null);
        return $this->buildTextResponse($data, $parsedContent, $usage);
    }

    /**
     * Convert an OpenAI assistant message into the ordered list of
     * provider blocks the {@see LLMContentParser} dispatcher can
     * normalise. Reasoning is sourced from `message.reasoning_content`
     * (OpenAI o-series, DeepSeek, MiniMax-M3) → `message.reasoning` →
     * inline reasoning tags inside `message.content`; both can be
     * present and are concatenated with a blank line.
     *
     * The emitted `ContentBlock::thinking` carries an empty `signature`
     * — OpenAI-compatible hosts don't sign chain-of-thought. The
     * Anthropic outbound path drops unsigned thinking blocks instead
     * of forwarding them, so a mid-task driver switch cannot break
     * Anthropic chain continuity.
     *
     * @param  array<string, mixed> $message
     * @return list<array<string, mixed>>
     */
    private function resolveMessageBlocks(array $message): array
    {
        $structured = $this->extractStructuredReasoning($message);

        $rawContent = $message['content'] ?? null;
        $cleanedText = '';
        $inlineReasoning = '';
        if (is_string($rawContent)) {
            $split = ThinkingTagExtractor::split($rawContent);
            $cleanedText = $split['text'];
            $inlineReasoning = $split['reasoning'];
        }

        $reasoningParts = array_filter(
            [$structured, $inlineReasoning],
            static fn(string $part): bool => $part !== '',
        );
        $totalReasoning = implode("\n\n", $reasoningParts);

        $blocks = [];
        if ($totalReasoning !== '') {
            $blocks[] = ['type' => 'thinking', 'thinking' => $totalReasoning, 'signature' => ''];
        }
        if (is_string($rawContent)) {
            if ($cleanedText !== '') {
                $blocks[] = ['type' => 'text', 'text' => $cleanedText];
            }
        } elseif (is_array($rawContent)) {
            foreach ($rawContent as $part) {
                if (is_array($part)) {
                    $blocks[] = $part;
                }
            }
        }

        return $blocks;
    }

    /**
     * First non-empty `reasoning_content` / `reasoning` value on the
     * message (structured field wins). Empty string is treated as
     * "absent" so a `reasoning_content: ""` payload doesn't surface
     * an empty reasoning block.
     *
     * @param  array<string, mixed> $message
     */
    private function extractStructuredReasoning(array $message): string
    {
        foreach (['reasoning_content', 'reasoning'] as $key) {
            $value = $message[$key] ?? null;
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @param array{contentBlocks: list<ContentBlock>, textContent: string} $parsedContent
     */
    private function buildTextResponse(array $data, array $parsedContent, Usage $usage): LLMResponse
    {
        return new LLMResponse(
            content: $parsedContent['textContent'],
            toolCalls: [],
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            completionId: (string) ($data['id'] ?? ''),
            contentBlocks: $parsedContent['contentBlocks'],
            usage: $usage,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $message
     * @param array{contentBlocks: list<ContentBlock>, textContent: string} $parsedContent
     */
    private function buildToolCallsResponse(array $data, array $message, array $parsedContent): LLMResponse
    {
        $toolCalls = [];
        foreach (($message['tool_calls'] ?? []) as $toolCall) {
            $toolCalls[] = new ToolCall(
                providerCallId: (string) ($toolCall['id'] ?? ''),
                toolName: (string) ($toolCall['function']['name'] ?? ''),
                arguments: $this->parseToolArguments($toolCall['function']['arguments'] ?? '{}'),
            );
        }
        $usage = $this->buildUsage(is_array($data['usage'] ?? null) ? $data['usage'] : null);

        return new LLMResponse(
            content: $parsedContent['textContent'] !== '' ? $parsedContent['textContent'] : null,
            toolCalls: $toolCalls,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            completionId: (string) ($data['id'] ?? ''),
            contentBlocks: $parsedContent['contentBlocks'],
            usage: $usage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseToolArguments(mixed $rawArguments): array
    {
        if (is_string($rawArguments)) {
            return json_decode($rawArguments, true) ?? [];
        }
        return (array) $rawArguments;
    }

    /**
     * @param array<string, mixed>|null $usage
     */
    private function buildUsage(?array $usage): Usage
    {
        return Usage::fromProviderUsage($usage, 'openai');
    }

    public static function getName(): string
    {
        return self::PROVIDER_KEY;
    }

    public static function getDisplayName(): string
    {
        return 'OpenAI Compatible';
    }

    /**
     * Translate a message's `content` to OpenAI's wire shape. Strings
     * pass through; ContentBlock lists become OpenAI's content-part
     * format: `{type:"text", text}` and
     * `{type:"image_url", image_url:{url:"data:..." | <url>}}`.
     */
    private static function normalizeContent(mixed $content): mixed
    {
        if ($content === null || is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return (string) $content;
        }
        $parts = [];
        foreach ($content as $b) {
            if (!is_array($b)) {
                continue;
            }
            $part = self::contentBlockToPart($b);
            if ($part !== null) {
                $parts[] = $part;
            }
        }
        return $parts === [] ? '' : $parts;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private static function contentBlockToPart(array $block): ?array
    {
        $type = $block['type'] ?? null;
        return match ($type) {
            ContentBlock::TYPE_TEXT,
            ContentBlock::TYPE_THINKING => ['type' => 'text', 'text' => (string) ($block['text'] ?? '')],
            ContentBlock::TYPE_REDACTED_THINKING => ['type' => 'text', 'text' => '[Redacted Thinking]'],
            ContentBlock::TYPE_IMAGE => self::imageBlockToPart($block),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private static function imageBlockToPart(array $block): ?array
    {
        if (isset($block['base64']) && is_string($block['base64']) && $block['base64'] !== '' && isset($block['mediaType'])) {
            return [
                'type'     => 'image_url',
                'image_url' => ['url' => 'data:' . $block['mediaType'] . ';base64,' . $block['base64']],
            ];
        }
        if (isset($block['url']) && is_string($block['url']) && $block['url'] !== '') {
            return [
                'type'     => 'image_url',
                'image_url' => ['url' => $block['url']],
            ];
        }

        return null;
    }
}

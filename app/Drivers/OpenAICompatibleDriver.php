<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Drivers\Utilities\LLMContentParser;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall;
use Spora\Tools\Attributes\ToolSetting;

#[ToolSetting(key: 'api_key', label: 'API Key', type: 'password', description: 'API key for the OpenAI-compatible endpoint. Leave empty for local models.', required: false, )]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Base URL of the API endpoint (e.g. https://api.openai.com/v1).', required: false, default: 'https://api.openai.com/v1')]
#[ToolSetting(key: 'model', label: 'Model', type: 'text', description: 'Model identifier (e.g. gpt-4o, gpt-4-turbo, o1-preview).', required: false, default: 'gpt-4o')]
#[ToolSetting(key: 'temperature', label: 'Temperature', type: 'text', description: 'Sampling temperature (0.0–2.0). Lower is more deterministic.', required: false, default: '0.7', validation: '/^[0-2](\.[0-9]+)?$/')]
#[ToolSetting(key: 'max_tokens_output', label: 'Max Output Tokens', type: 'text', description: 'Maximum number of tokens to generate (output buffer).', required: false, default: '4096')]
#[ToolSetting(key: 'context_window', label: 'Context Window', type: 'text', description: 'Total context window size for this model (input + output combined, e.g. 128000).', required: false, default: '128000')]
#[ToolSetting(key: 'timeout', label: 'Timeout (seconds)', type: 'text', description: 'HTTP timeout per request. Increase for slow models (e.g. local Ollama).', required: false, default: '300')]
final class OpenAICompatibleDriver extends AbstractCompatibleDriver
{
    private const PROVIDER_KEY = 'openai_compatible';

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
     * treated as text-only unless the operator overrides via a subclass.
     */
    public function supportsImageInput(): bool
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
        $finishReason = (string) ($choice['finish_reason'] ?? '');
        $message = $choice['message'] ?? [];
        $parsedContent = LLMContentParser::parse($message['content'] ?? null);

        if ($finishReason === 'tool_calls') {
            return $this->buildToolCallsResponse($data, $message, $parsedContent);
        }

        return new LLMResponse(
            content: $parsedContent['content'],
            toolCalls: [],
            inputTokens: (int) ($data['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['completion_tokens'] ?? 0),
            completionId: (string) ($data['id'] ?? ''),
            reasoning: $parsedContent['reasoning'],
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $message
     * @param array<string, mixed> $parsedContent
     */
    private function buildToolCallsResponse(array $data, array $message, array $parsedContent): LLMResponse
    {
        $toolCalls = [];
        foreach (($message['tool_calls'] ?? []) as $tc) {
            $toolCalls[] = new ToolCall(
                providerCallId: (string) ($tc['id'] ?? ''),
                toolName: (string) ($tc['function']['name'] ?? ''),
                arguments: $this->parseToolArguments($tc['function']['arguments'] ?? '{}'),
            );
        }
        return new LLMResponse(
            content: $parsedContent['content'] !== '' ? $parsedContent['content'] : null,
            toolCalls: $toolCalls,
            inputTokens: (int) ($data['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['completion_tokens'] ?? 0),
            completionId: (string) ($data['id'] ?? ''),
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
        $result = null;
        if ($type === 'text') {
            $result = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
        } elseif ($type === 'image') {
            $result = self::imageBlockToPart($block);
        }

        return $result;
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

<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Psr\Log\LoggerInterface;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Drivers\Utilities\LLMContentParser;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[ToolSetting(key: 'api_key', label: 'API Key', type: 'password', description: 'API key for the OpenAI-compatible endpoint. Leave empty for local models.', required: false, scope: 'global')]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Base URL of the API endpoint (e.g. https://api.openai.com/v1).', required: false, scope: 'global', default: 'https://api.openai.com/v1')]
#[ToolSetting(key: 'model', label: 'Model', type: 'text', description: 'Model identifier (e.g. gpt-4o, gpt-4-turbo, o1-preview).', required: false, scope: 'global', default: 'gpt-4o')]
#[ToolSetting(key: 'temperature', label: 'Temperature', type: 'text', description: 'Sampling temperature (0.0–2.0). Lower is more deterministic.', required: false, scope: 'global', default: '0.7')]
#[ToolSetting(key: 'max_tokens', label: 'Max Tokens', type: 'text', description: 'Maximum number of tokens to generate.', required: false, scope: 'global', default: '4096')]
#[ToolSetting(key: 'timeout', label: 'Timeout (seconds)', type: 'text', description: 'HTTP timeout per request. Increase for slow models (e.g. local Ollama).', required: false, scope: 'global', default: '45')]
final class OpenAICompatibleDriver implements LLMDriverInterface, LLMDriverConfigInterface
{
    public function __construct(
        private readonly string              $apiKey,
        private readonly string              $model,
        private readonly string              $baseUrl,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface    $logger = null,
        private readonly ?int                $timeout = null,
    ) {}

    // ── LLMDriverInterface ──────────────────────────────────────────────────────

    public function getProviderName(): string
    {
        return 'openai_compatible';
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $messages = array_merge(
            [['role' => 'system', 'content' => $request->systemPrompt]],
            $request->messages,
        );

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
        ];

        if ($request->tools !== []) {
            $body['tools'] = $request->tools;
            $body['tool_choice'] = 'auto';
        }

        $url = rtrim($this->baseUrl, '/') . '/chat/completions';
        $this->logger?->debug('LLM Request (OpenAI)', ['url' => $url, 'payload' => $body]);

        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $headers,
            'json' => $body,
            'timeout' => $this->timeout ?? 45,
        ]);

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

        /** @var array<string, mixed> $data */
        $data = $response->toArray();
        $this->logger?->debug('LLM Response (OpenAI)', ['status' => $statusCode, 'data' => $data]);

        $completionId = (string) ($data['id'] ?? '');
        $inputTokens = (int) ($data['usage']['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($data['usage']['completion_tokens'] ?? 0);

        $choice = $data['choices'][0] ?? [];
        $finishReason = $choice['finish_reason'] ?? '';
        $message = $choice['message'] ?? [];

        $parsedContent = LLMContentParser::parse($message['content'] ?? null);

        if ($finishReason === 'tool_calls') {
            $toolCalls = [];

            foreach (($message['tool_calls'] ?? []) as $tc) {
                $rawArguments = $tc['function']['arguments'] ?? '{}';
                $arguments = is_string($rawArguments)
                    ? (json_decode($rawArguments, true) ?? [])
                    : (array) $rawArguments;

                $toolCalls[] = new ToolCall(
                    providerCallId: (string) ($tc['id'] ?? ''),
                    toolName: (string) ($tc['function']['name'] ?? ''),
                    arguments: $arguments,
                );
            }

            return new LLMResponse(
                content: $parsedContent['content'] !== '' ? $parsedContent['content'] : null,
                toolCalls: $toolCalls,
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                completionId: $completionId,
                reasoning: $parsedContent['reasoning'],
            );
        }

        return new LLMResponse(
            content: $parsedContent['content'],
            toolCalls: [],
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            completionId: $completionId,
            reasoning: $parsedContent['reasoning'],
        );
    }

    // ── LLMDriverConfigInterface ────────────────────────────────────────────────

    public static function getName(): string
    {
        return 'openai_compatible';
    }

    public static function getDisplayName(): string
    {
        return 'OpenAI Compatible';
    }

    /** @return list<class-string> */
    public static function getDefaultTools(): array
    {
        return [];
    }
}

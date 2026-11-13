<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Psr\Log\LoggerInterface;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Drivers\OpenAI\ChatCompletionsContent;
use Spora\Drivers\OpenAI\ChatCompletionsReasoning;
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
#[ToolSetting(key: 'effort', label: 'Effort', type: 'text', default: '', description: "Default reasoning effort. Empty = the model default. On GPT-5.4 and later, Chat Completions only accepts 'None' when the agent uses tools — use the OpenAI Responses driver for reasoning with tools. A level this endpoint does not offer is refused.")]
#[ToolSetting(key: 'effort_levels', label: 'Effort levels', type: 'textarea', default: '', description: 'Comma-separated list of the reasoning-effort levels this endpoint accepts. Empty = the model\'s own set. A level the endpoint does not take is refused.')]
#[ToolSetting(key: 'supports_effort', label: 'Supports reasoning effort', type: 'toggle', default: true, description: 'Turn this off for an endpoint with no reasoning-effort parameter at all — the agent then has no effort picker and any effort sent is refused.')]
#[ToolSetting(key: 'timeout', label: 'Timeout (seconds)', type: 'text', description: 'HTTP timeout per request. Increase for slow models (e.g. local Ollama).', required: false, default: '300')]
final class OpenAICompatibleDriver extends AbstractCompatibleDriver
{
    private const PROVIDER_KEY = 'openai_compatible';

    private readonly ChatCompletionsReasoning $reasoning;

    /**
     * @param list<string> $effortLevels The levels the configuration names.
     *        Empty means the model's own set applies.
     */
    public function __construct(
        string              $apiKey,
        string              $model,
        string              $baseUrl,
        HttpClientInterface $httpClient,
        ?LoggerInterface    $logger = null,
        ?int                $timeout = null,
        ?bool               $supportsImageInput = null,
        array               $effortLevels = [],
        ?bool               $supportsEffort = null,
    ) {
        parent::__construct(
            $apiKey,
            $model,
            $baseUrl,
            $httpClient,
            $logger,
            $timeout,
            $supportsImageInput,
            $effortLevels,
            $supportsEffort,
        );

        $this->reasoning = new ChatCompletionsReasoning($model);
    }

    public function getProviderName(): string
    {
        return static::getName();
    }

    /**
     * The vision-capable OpenAI families, asked of the one place a model
     * name is interpreted.
     */
    protected function modelBasedSupportsImageInput(): bool
    {
        return ModelFamily::openAISupportsImageInput($this->model);
    }

    /**
     * Chat Completions spells every level the way the canonical scale
     * does, so the mapping is the identity — but it is written out so
     * that a level this driver cannot express is visibly absent.
     *
     * @return array<string, string>
     */
    protected function defaultEffortLevels(): array
    {
        return [
            EffortLevel::None->value    => EffortLevel::None->value,
            EffortLevel::Minimal->value => EffortLevel::Minimal->value,
            EffortLevel::Low->value     => EffortLevel::Low->value,
            EffortLevel::Medium->value  => EffortLevel::Medium->value,
            EffortLevel::High->value    => EffortLevel::High->value,
            EffortLevel::XHigh->value   => EffortLevel::XHigh->value,
            EffortLevel::Max->value     => EffortLevel::Max->value,
        ];
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
            [['role' => $this->reasoning->systemRole(), 'content' => $request->systemPrompt]],
            array_map(
                static function (array $msg): array {
                    $content = $msg['content'] ?? null;
                    if ($content === null || is_string($content)) {
                        return $msg;
                    }
                    $msg['content'] = ChatCompletionsContent::normalize($content);
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
        $body = array_merge(
            [
                'model'    => $this->model,
                'messages' => $messages,
            ],
            $this->reasoning->tokenParameters($request),
        );

        if ($request->tools !== []) {
            $body['tools'] = $request->tools;
            $body['tool_choice'] = 'auto';
        }

        $effort = $this->reasoning->effort($request, $this->effortLevels());
        if ($effort !== null) {
            $body['reasoning_effort'] = $effort;
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
}

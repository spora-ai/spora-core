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

    public function __construct(
        string              $apiKey,
        string              $model,
        string              $baseUrl,
        HttpClientInterface $httpClient,
        ?LoggerInterface    $logger = null,
        ?int                $timeout = null,
        ?AnthropicDriverOptions $options = null,
    ) {
        parent::__construct($apiKey, $model, $baseUrl, $httpClient, $logger, $timeout);
        $this->temperature    = $options?->temperature;
        $this->thinkingBudget = $options?->thinkingBudget;
    }

    public function getProviderName(): string
    {
        return self::PROVIDER_KEY;
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
        $body = [
            'model'      => $this->model,
            'system'     => $request->systemPrompt,
            'messages'   => $messages,
            'max_tokens' => $request->maxTokens,
        ];

        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        if ($this->temperature !== null) {
            $body['temperature'] = $this->temperature;
        }

        if ($this->thinkingBudget !== null) {
            $body['thinking'] = [
                'type'          => 'enabled',
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

        $completionId  = (string) ($data['id'] ?? '');
        $inputTokens   = (int) ($data['usage']['input_tokens'] ?? 0);
        $outputTokens  = (int) ($data['usage']['output_tokens'] ?? 0);
        $stopReason    = (string) ($data['stop_reason'] ?? '');
        $contentBlocks = (array) ($data['content'] ?? []);

        $parsedContent = LLMContentParser::parse($contentBlocks);

        if ($stopReason !== 'tool_use') {
            return new LLMResponse(
                content: $parsedContent['content'],
                toolCalls: [],
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                completionId: $completionId,
                reasoning: $parsedContent['reasoning'],
            );
        }

        return new LLMResponse(
            content: $parsedContent['content'] !== '' ? $parsedContent['content'] : null,
            toolCalls: $this->extractToolCalls($contentBlocks),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            completionId: $completionId,
            reasoning: $parsedContent['reasoning'],
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
                $converted[] = $this->buildAssistantToolUseMessage($msg['tool_calls']);
                continue;
            }

            if ($toolResults !== []) {
                $converted[] = $this->flushToolResults($toolResults);
                $toolResults = [];
            }

            $converted[] = ['role' => $role, 'content' => $msg['content'] ?? ''];
        }

        // Flush any trailing tool results
        if ($toolResults !== []) {
            $converted[] = $this->flushToolResults($toolResults);
        }

        return $converted;
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
     * @param  list<array<string, mixed>>  $toolCalls
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    private function buildAssistantToolUseMessage(array $toolCalls): array
    {
        $contentBlocks = [];

        foreach ($toolCalls as $tc) {
            $contentBlocks[] = $this->buildToolUseBlock($tc);
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

    public static function getName(): string
    {
        return self::PROVIDER_KEY;
    }

    public static function getDisplayName(): string
    {
        return 'Anthropic Compatible';
    }
}

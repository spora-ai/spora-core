<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Psr\Log\LoggerInterface;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnthropicCompatibleDriver implements LLMDriverInterface
{
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string              $apiKey,
        private readonly string              $model,
        private readonly string              $baseUrl,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface    $logger = null,
    ) {}

    public function getProviderName(): string
    {
        return 'anthropic';
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $tools = $this->convertTools($request->tools);
        $messages = $this->convertMessages($request->messages);

        $body = [
            'model'      => $this->model,
            'system'     => $request->systemPrompt,
            'messages'   => $messages,
            'max_tokens' => $request->maxTokens,
        ];

        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        $url = rtrim($this->baseUrl, '/');
        $this->logger?->debug('LLM Request (Anthropic)', ['url' => $url, 'payload' => $body]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'Content-Type'      => 'application/json',
            ],
            'json'    => $body,
            'timeout' => 45,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 429) {
            throw new LLMRateLimitException('Anthropic rate limit exceeded (HTTP 429).');
        }

        if ($statusCode >= 400) {
            $rawBody = $response->getContent(throw: false);
            throw new LLMProviderException("Anthropic API error {$statusCode}: {$rawBody}");
        }

        /** @var array<string, mixed> $data */
        $data = $response->toArray();
        $this->logger?->debug('LLM Response (Anthropic)', ['status' => $statusCode, 'data' => $data]);

        $completionId = (string) ($data['id'] ?? '');
        $inputTokens  = (int) ($data['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($data['usage']['output_tokens'] ?? 0);
        $stopReason   = (string) ($data['stop_reason'] ?? '');
        $contentBlocks = (array) ($data['content'] ?? []);

        if ($stopReason === 'tool_use') {
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

            return new LLMResponse(
                content: null,
                toolCalls: $toolCalls,
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                completionId: $completionId,
            );
        }

        // end_turn — extract text from content blocks
        $textContent = '';
        foreach ($contentBlocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $textContent .= (string) ($block['text'] ?? '');
            }
        }

        return new LLMResponse(
            content: $textContent,
            toolCalls: [],
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            completionId: $completionId,
        );
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

            // Flush accumulated tool results when we hit a non-tool message
            if ($role !== 'tool' && $toolResults !== []) {
                $converted[]  = ['role' => 'user', 'content' => $toolResults];
                $toolResults  = [];
            }

            if ($role === 'tool') {
                // Accumulate; will be flushed as a single user turn
                $toolResults[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => (string) ($msg['tool_call_id'] ?? ''),
                    'content'     => (string) ($msg['content'] ?? ''),
                ];
                continue;
            }

            if ($role === 'assistant' && isset($msg['tool_calls'])) {
                $contentBlocks = [];

                foreach ($msg['tool_calls'] as $tc) {
                    $rawArguments = $tc['function']['arguments'] ?? '{}';
                    $input        = is_string($rawArguments)
                        ? (json_decode($rawArguments, true) ?? [])
                        : (array) $rawArguments;

                    $contentBlocks[] = [
                        'type'  => 'tool_use',
                        'id'    => (string) ($tc['id'] ?? ''),
                        'name'  => (string) ($tc['function']['name'] ?? ''),
                        'input' => $input,
                    ];
                }

                $converted[] = ['role' => 'assistant', 'content' => $contentBlocks];
                continue;
            }

            $converted[] = ['role' => $role, 'content' => $msg['content'] ?? ''];
        }

        // Flush any trailing tool results
        if ($toolResults !== []) {
            $converted[] = ['role' => 'user', 'content' => $toolResults];
        }

        return $converted;
    }
}

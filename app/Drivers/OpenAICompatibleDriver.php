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

final class OpenAICompatibleDriver implements LLMDriverInterface
{
    public function __construct(
        private readonly string              $apiKey,
        private readonly string              $model,
        private readonly string              $baseUrl,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface    $logger = null,
    ) {}

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
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $request->maxTokens,
            'temperature' => $request->temperature,
        ];

        if ($request->tools !== []) {
            $body['tools']       = $request->tools;
            $body['tool_choice'] = 'auto';
        }

        $url = rtrim($this->baseUrl, '/') . '/chat/completions';
        $this->logger?->debug('LLM Request (OpenAI)', ['url' => $url, 'payload' => $body]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'json'    => $body,
            'timeout' => 45, // Fix 2: Prevent hanging indefinitely
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 429) {
            throw new LLMRateLimitException('OpenAI rate limit exceeded (HTTP 429).');
        }

        if ($statusCode >= 400) {
            $body = $response->getContent(throw: false);
            throw new LLMProviderException("OpenAI API error {$statusCode}: {$body}");
        }

        /** @var array<string, mixed> $data */
        $data = $response->toArray();
        $this->logger?->debug('LLM Response (OpenAI)', ['status' => $statusCode, 'data' => $data]);

        $completionId  = (string) ($data['id'] ?? '');
        $inputTokens   = (int) ($data['usage']['prompt_tokens'] ?? 0);
        $outputTokens  = (int) ($data['usage']['completion_tokens'] ?? 0);

        $choice      = $data['choices'][0] ?? [];
        $finishReason = $choice['finish_reason'] ?? '';
        $message      = $choice['message'] ?? [];

        if ($finishReason === 'tool_calls') {
            $toolCalls = [];

            foreach (($message['tool_calls'] ?? []) as $tc) {
                $rawArguments = $tc['function']['arguments'] ?? '{}';
                $arguments    = is_string($rawArguments)
                    ? (json_decode($rawArguments, true) ?? [])
                    : (array) $rawArguments;

                $toolCalls[] = new ToolCall(
                    providerCallId: (string) ($tc['id'] ?? ''),
                    toolName: (string) ($tc['function']['name'] ?? ''),
                    arguments: $arguments,
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

        return new LLMResponse(
            content: (string) ($message['content'] ?? ''),
            toolCalls: [],
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            completionId: $completionId,
        );
    }
}

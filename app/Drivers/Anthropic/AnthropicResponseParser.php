<?php

declare(strict_types=1);

namespace Spora\Drivers\Anthropic;

use Psr\Log\LoggerInterface;
use Spora\Drivers\Utilities\LLMContentParser;
use Spora\Drivers\Utilities\ToolArgumentsNormalizer;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall;
use Spora\Drivers\ValueObjects\Usage;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Parses an Anthropic `/v1/messages` response into the canonical
 * {@see LLMResponse}. Pure transformation apart from the optional
 * logger used to record the raw response payload at debug level.
 *
 * Split out of {@see \Spora\Drivers\AnthropicCompatibleDriver} so the
 * Anthropic-specific response shape lives next to the rules that
 * produce it and the driver class stays focused on transport.
 */
final class AnthropicResponseParser
{
    public function __construct(
        private readonly ?LoggerInterface $logger,
    ) {}

    public function parse(ResponseInterface $response): LLMResponse
    {
        $statusCode = $response->getStatusCode();

        /** @var array<string, mixed> $data */
        $data = $response->toArray();
        $this->logger?->debug('LLM Response (Anthropic)', ['status' => $statusCode, 'data' => $data]);

        $parsedContent = LLMContentParser::parse(is_array($data['content'] ?? null) ? $data['content'] : []);
        $usage = Usage::fromProviderUsage(is_array($data['usage'] ?? null) ? $data['usage'] : null, 'anthropic');

        return $this->buildResponse(
            parsed: $parsedContent,
            usage: $usage,
            completionId: (string) ($data['id'] ?? ''),
            stopReason: (string) ($data['stop_reason'] ?? ''),
            rawBlocks: is_array($data['content'] ?? null) ? $data['content'] : [],
        );
    }

    /**
     * @param array{contentBlocks: list<\Spora\Drivers\ValueObjects\ContentBlock>, textContent: string} $parsed
     * @param list<array<string, mixed>> $rawBlocks
     */
    private function buildResponse(array $parsed, Usage $usage, string $completionId, string $stopReason, array $rawBlocks): LLMResponse
    {
        $isToolUse = $stopReason === 'tool_use';
        $textContent = $isToolUse ? $this->nullableText($parsed['textContent']) : $parsed['textContent'];
        $toolCalls = $isToolUse ? $this->extractToolCalls($rawBlocks) : [];

        return new LLMResponse(
            content: $textContent,
            toolCalls: $toolCalls,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            completionId: $completionId,
            contentBlocks: $parsed['contentBlocks'],
            usage: $usage,
        );
    }

    private function nullableText(string $text): ?string
    {
        return $text !== '' ? $text : null;
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
                arguments: ToolArgumentsNormalizer::unboxItemWrappers((array) ($block['input'] ?? [])),
            );
        }

        return $toolCalls;
    }
}

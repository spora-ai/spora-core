<?php

declare(strict_types=1);

namespace Spora\Drivers\Responses;

use Psr\Log\LoggerInterface;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Utilities\ContentBlockParserRegistry;
use Spora\Drivers\ValueObjects\ContentBlock;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall;
use Spora\Drivers\ValueObjects\Usage;

/**
 * Parses an OpenAI `/v1/responses` response into the canonical
 * {@see LLMResponse}. Pure transformation apart from the optional
 * logger.
 *
 * The response body is not a message: it is a list of output *items*,
 * each of a different kind. The plain text has to be aggregated by hand
 * — the `output_text` convenience property exists only in the Python and
 * JavaScript SDKs, so a PHP client walks the array itself.
 */
final class ResponsesResponseParser
{
    private readonly ContentBlockParserRegistry $registry;

    public function __construct(
        private readonly ?LoggerInterface $logger,
    ) {
        $this->registry = new ContentBlockParserRegistry();
    }

    /**
     * @param array<string, mixed>  $data       the response body
     * @param array<string, string> $toolNames  the wire name of each tool => the name Spora knows
     */
    public function parse(array $data, array $toolNames = []): LLMResponse
    {
        $this->assertUsable($data);

        $text = '';
        $toolCalls = [];
        $contentBlocks = [];

        foreach ($this->outputItems($data) as $item) {
            $type = (string) ($item['type'] ?? '');

            if ($type === 'message') {
                $messageText = $this->messageText($item);
                $text .= $messageText;
                if ($messageText !== '') {
                    // The history replays an assistant row from its
                    // content blocks when it has any, so the text has to
                    // be a block of its own — a reasoning block alone
                    // would replace it.
                    $contentBlocks[] = ContentBlock::text($messageText);
                }
                continue;
            }

            if ($type === 'reasoning') {
                $block = $this->reasoningBlock($item);
                if ($block !== null) {
                    $contentBlocks[] = $block;
                }
                continue;
            }

            if ($type === 'function_call') {
                $call = $this->toolCall($item, $toolNames);
                if ($call !== null) {
                    $toolCalls[] = $call;
                }
            }
        }

        $usage = Usage::fromProviderUsage(
            is_array($data['usage'] ?? null) ? $data['usage'] : null,
            'openai_responses',
        );

        return new LLMResponse(
            content: $toolCalls !== [] ? ($text !== '' ? $text : null) : $text,
            toolCalls: $toolCalls,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            completionId: (string) ($data['id'] ?? ''),
            contentBlocks: $contentBlocks,
            usage: $usage,
        );
    }

    /**
     * The status is read before the output: a 200 can carry a response
     * that never produced anything usable.
     *
     * @param array<string, mixed> $data
     */
    private function assertUsable(array $data): void
    {
        $status = (string) ($data['status'] ?? '');

        if ($status === 'failed') {
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];
            $message = (string) ($error['message'] ?? 'no message');

            throw new LLMProviderException("OpenAI Responses API failed: {$message}");
        }

        if ($status === 'incomplete') {
            $details = is_array($data['incomplete_details'] ?? null) ? $data['incomplete_details'] : [];

            $this->logger?->warning('OpenAI Responses API returned an incomplete response.', [
                'reason' => (string) ($details['reason'] ?? 'unknown'),
            ]);

            return;
        }

        if ($status !== '' && $status !== 'completed') {
            throw new LLMProviderException("OpenAI Responses API returned status '{$status}'.");
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function outputItems(array $data): array
    {
        $output = $data['output'] ?? null;
        if (!is_array($output)) {
            return [];
        }

        $items = [];
        foreach ($output as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The assistant text lives in the `output_text` parts of the
     * message items.
     *
     * @param array<string, mixed> $item
     */
    private function messageText(array $item): string
    {
        $content = $item['content'] ?? null;
        if (!is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (!is_array($part) || ($part['type'] ?? '') !== 'output_text') {
                continue;
            }
            $text .= (string) ($part['text'] ?? '');
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function reasoningBlock(array $item): ?ContentBlock
    {
        $parser = $this->registry->for(ContentBlock::TYPE_REASONING);
        if ($parser === null) {
            return null;
        }

        return $parser->parse($item)->contentBlock;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, string> $toolNames
     */
    private function toolCall(array $item, array $toolNames): ?ToolCall
    {
        $name = (string) ($item['name'] ?? '');
        if ($name === '') {
            return null;
        }

        return new ToolCall(
            // The correlation key is `call_id`, not the item id: the
            // `function_call_output` that answers the call references it.
            providerCallId: (string) ($item['call_id'] ?? ''),
            toolName: $toolNames[$name] ?? $name,
            arguments: $this->arguments($item['arguments'] ?? '{}'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}

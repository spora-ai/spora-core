<?php

declare(strict_types=1);

namespace Spora\Agents\ValueObjects;

use Psr\Log\LoggerInterface;
use Spora\Drivers\Exceptions\UnknownContentBlockTypeException;
use Spora\Drivers\ValueObjects\ContentBlock;
use Spora\Drivers\ValueObjects\Usage;

/**
 * Optional provider state attached to one persisted task-history row.
 */
final readonly class HistoryMessageContext
{
    /**
     * @param list<ContentBlock> $contentBlocks
     * @param array<int, array{media_id: string, kind: string}>|null $attachments
     */
    public function __construct(
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public ?string $toolCallPayload = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public array $contentBlocks = [],
        public ?Usage $usage = null,
        public ?array $attachments = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tool_call_id' => $this->toolCallId,
            'tool_name' => $this->toolName,
            'tool_call_payload' => $this->toolCallPayload,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'content_blocks' => array_map(
                static fn(ContentBlock $block): array => $block->toArray(),
                $this->contentBlocks,
            ),
            'usage' => $this->usage?->toArray(),
            'attachments' => $this->attachments,
        ];
    }

    /**
     * Hydrate a `HistoryMessageContext` from a wire-array shape. Structured
     * `content_blocks` are the only source of reasoning — the legacy flat
     * `reasoning` column was dropped from `task_history` in favour of
     * `content_blocks[].type === "thinking"`.
     *
     * @param array<string, mixed> $data
     * @param LoggerInterface|null $logger Optional logger for warnings emitted when a
     *                                     stored row references an unknown `content_blocks.type`;
     *                                     falls back to `error_log()` when null.
     */
    public static function fromArray(array $data, ?LoggerInterface $logger = null): self
    {
        $contentBlocks = self::decodeContentBlocks($data['content_blocks'] ?? [], $logger);
        $usage = self::resolveUsage($data['usage'] ?? null);

        return new self(
            toolCallId: self::nullableString($data['tool_call_id'] ?? $data['toolCallId'] ?? null),
            toolName: self::nullableString($data['tool_name'] ?? $data['toolName'] ?? null),
            toolCallPayload: self::nullableString($data['tool_call_payload'] ?? $data['toolCallPayload'] ?? null),
            inputTokens: (int) ($data['input_tokens'] ?? $data['inputTokens'] ?? 0),
            outputTokens: (int) ($data['output_tokens'] ?? $data['outputTokens'] ?? 0),
            contentBlocks: $contentBlocks,
            usage: $usage,
            attachments: is_array($data['attachments'] ?? null) ? $data['attachments'] : null,
        );
    }

    /**
     * @param mixed $usageData
     */
    private static function resolveUsage(mixed $usageData): Usage
    {
        if ($usageData instanceof Usage) {
            return $usageData;
        }

        if (is_array($usageData)) {
            return Usage::fromArray($usageData);
        }

        return new Usage();
    }

    /**
     * @return list<ContentBlock>
     */
    private static function decodeContentBlocks(mixed $rawBlocks, ?LoggerInterface $logger): array
    {
        if (!is_array($rawBlocks)) {
            return [];
        }

        $blocks = [];
        foreach ($rawBlocks as $rawBlock) {
            if ($rawBlock instanceof ContentBlock) {
                $blocks[] = $rawBlock;
                continue;
            }
            if (!is_array($rawBlock)) {
                continue;
            }

            try {
                $blocks[] = ContentBlock::fromArray($rawBlock);
            } catch (UnknownContentBlockTypeException $exception) {
                self::warn($logger, $exception->getMessage());
            }
        }

        return $blocks;
    }

    private static function warn(?LoggerInterface $logger, string $message): void
    {
        if ($logger !== null) {
            $logger->warning($message);
            return;
        }

        error_log($message);
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}

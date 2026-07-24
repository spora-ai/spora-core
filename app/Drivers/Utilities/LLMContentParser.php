<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

use Spora\Drivers\ValueObjects\ContentBlock;

final class LLMContentParser
{
    private static ?ContentBlockParserRegistry $registry = null;

    /**
     * Normalise a provider completion payload into the canonical
     * `{contentBlocks, textContent}` shape the driver returns in
     * {@see \Spora\Drivers\ValueObjects\LLMResponse}. Reasoning is
     * surfaced only via the structured `content_blocks[]` of
     * `type === "thinking"` (provider-signed, replayable). Unsigned inline
     * `<think>…</think>` tags in the model text are stripped from the
     * surfaced `textContent` but their content is not surfaced — see
     * {@see ThinkingTagExtractor::strip()} for the rationale.
     *
     * @param string|array<int, mixed>|null $rawContent
     * @return array{contentBlocks: list<ContentBlock>, textContent: string}
     */
    public static function parse(string|array|null $rawContent): array
    {
        if (is_string($rawContent)) {
            return self::parseString($rawContent);
        }

        if (!is_array($rawContent)) {
            return self::emptyResult();
        }

        return self::parseStructuredBlocks($rawContent);
    }

    /**
     * @return array{contentBlocks: list<ContentBlock>, textContent: string}
     */
    private static function parseString(string $rawContent): array
    {
        $cleaned = ThinkingTagExtractor::strip($rawContent);

        return [
            'contentBlocks' => $cleaned !== '' ? [ContentBlock::text($cleaned)] : [],
            'textContent' => $cleaned,
        ];
    }

    /**
     * Walk the provider's structured content blocks and produce the
     * ordered {@see ContentBlock} list (for replay) plus the concatenated
     * `textContent` (for the legacy `content` field).
     *
     * @param array<int, mixed> $rawContent
     * @return array{contentBlocks: list<ContentBlock>, textContent: string}
     */
    private static function parseStructuredBlocks(array $rawContent): array
    {
        $contentBlocks = [];
        $textContent = '';
        $registry = self::registry();

        foreach ($rawContent as $block) {
            if (!is_array($block)) {
                continue;
            }
            self::appendStructuredBlock($block, $registry, $contentBlocks, $textContent);
        }

        return [
            'contentBlocks' => $contentBlocks,
            'textContent' => $textContent,
        ];
    }

    /**
     * Append a single provider block to the accumulator. `tool_use` blocks
     * are reconstructed from the raw array directly; every other type is
     * routed through the {@see ContentBlockParserRegistry}.
     *
     * @param array<string, mixed> $block
     * @param list<ContentBlock> $contentBlocks
     */
    private static function appendStructuredBlock(
        array $block,
        ContentBlockParserRegistry $registry,
        array &$contentBlocks,
        string &$textContent,
    ): void {
        $type = (string) ($block['type'] ?? '');
        if ($type === ContentBlock::TYPE_TOOL_USE) {
            $input = is_array($block['input'] ?? null) ? $block['input'] : [];
            $contentBlocks[] = ContentBlock::toolUse(
                (string) ($block['id'] ?? ''),
                (string) ($block['name'] ?? ''),
                $input,
            );
            return;
        }

        $parser = $registry->for($type);
        if ($parser === null) {
            return;
        }

        $parsed = $parser->parse($block);
        $textContent .= $parsed->textContent;
        if ($parsed->contentBlock !== null) {
            $contentBlocks[] = $parsed->contentBlock;
        }
    }

    /**
     * @return array{contentBlocks: list<ContentBlock>, textContent: string}
     */
    private static function emptyResult(): array
    {
        return ['contentBlocks' => [], 'textContent' => ''];
    }

    private static function registry(): ContentBlockParserRegistry
    {
        return self::$registry ??= new ContentBlockParserRegistry();
    }
}

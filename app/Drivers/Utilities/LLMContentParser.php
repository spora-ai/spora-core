<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

use Spora\Drivers\ValueObjects\ContentBlock;

final class LLMContentParser
{
    private static ?ContentBlockParserRegistry $registry = null;

    /**
     * Structured provider blocks take display precedence over unsigned inline
     * tags so the same reasoning is never shown twice or replayed unsigned.
     *
     * @param string|array<int, mixed>|null $rawContent
     * @return array{contentBlocks: list<ContentBlock>, displayReasoning: string|null, textContent: string}
     */
    public static function parse(string|array|null $rawContent): array
    {
        if (is_string($rawContent)) {
            return self::parseString($rawContent);
        }

        if (!is_array($rawContent)) {
            return self::emptyResult();
        }

        $structured = self::parseStructuredBlocks($rawContent);
        $displayReasoning = self::pickDisplayReasoning($structured);

        return [
            'contentBlocks' => $structured['contentBlocks'],
            'displayReasoning' => $displayReasoning,
            'textContent' => $structured['textContent'],
        ];
    }

    /**
     * @return array{contentBlocks: list<ContentBlock>, displayReasoning: string|null, textContent: string}
     */
    private static function parseString(string $rawContent): array
    {
        $extracted = ThinkingTagExtractor::extract($rawContent);

        return [
            'contentBlocks' => $extracted['textContent'] !== ''
                ? [ContentBlock::text($extracted['textContent'])]
                : [],
            'displayReasoning' => $extracted['displayReasoning'],
            'textContent' => $extracted['textContent'],
        ];
    }

    /**
     * Walk the provider's structured content blocks and extract the three
     * payload axes: ordered {@see ContentBlock} list (for replay), the
     * concatenated text content (for the legacy `content` field), and the
     * per-block parsing carry state used by
     * {@see self::pickDisplayReasoning()}.
     *
     * @param array<int, mixed> $rawContent
     * @return array{contentBlocks: list<ContentBlock>, textContent: string, structuredReasoning: string|null, tagReasoning: string|null, hasStructuredReasoning: bool}
     */
    private static function parseStructuredBlocks(array $rawContent): array
    {
        $contentBlocks = [];
        $textContent = '';
        $structuredReasoning = null;
        $tagReasoning = null;
        $hasStructuredReasoning = false;
        $registry = self::registry();

        foreach ($rawContent as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            if ($type === ContentBlock::TYPE_TOOL_USE) {
                $contentBlocks[] = ContentBlock::toolUse(
                    (string) ($block['id'] ?? ''),
                    (string) ($block['name'] ?? ''),
                    is_array($block['input'] ?? null) ? $block['input'] : [],
                );
                continue;
            }

            $parser = $registry->for($type);
            if ($parser === null) {
                continue;
            }

            $parsed = $parser->parse($block);
            $textContent .= $parsed->textContent;
            if ($parsed->contentBlock !== null) {
                $contentBlocks[] = $parsed->contentBlock;
            }

            if ($parsed->displayReasoning === null) {
                continue;
            }

            if ($type === ContentBlock::TYPE_THINKING || $type === ContentBlock::TYPE_REDACTED_THINKING) {
                $hasStructuredReasoning = true;
                $structuredReasoning = self::appendReasoning($structuredReasoning, $parsed->displayReasoning);
                continue;
            }

            $tagReasoning = self::appendReasoning($tagReasoning, $parsed->displayReasoning);
        }

        return [
            'contentBlocks' => $contentBlocks,
            'textContent' => $textContent,
            'structuredReasoning' => $structuredReasoning,
            'tagReasoning' => $tagReasoning,
            'hasStructuredReasoning' => $hasStructuredReasoning,
        ];
    }

    /**
     * Prefer structured reasoning (signed `thinking`/`redacted_thinking` blocks)
     * over inline tag extraction; the tag would be unsigned and replaying it
     * is unsafe.
     *
     * @param array{structuredReasoning: string|null, tagReasoning: string|null, hasStructuredReasoning: bool} $structured
     */
    private static function pickDisplayReasoning(array $structured): ?string
    {
        if ($structured['hasStructuredReasoning']) {
            return $structured['structuredReasoning'];
        }

        return $structured['tagReasoning'];
    }

    private static function appendReasoning(?string $current, string $next): string
    {
        return $current === null ? $next : $current . "\n" . $next;
    }

    /**
     * @return array{contentBlocks: list<ContentBlock>, displayReasoning: null, textContent: string}
     */
    private static function emptyResult(): array
    {
        return ['contentBlocks' => [], 'displayReasoning' => null, 'textContent' => ''];
    }

    private static function registry(): ContentBlockParserRegistry
    {
        return self::$registry ??= new ContentBlockParserRegistry();
    }
}

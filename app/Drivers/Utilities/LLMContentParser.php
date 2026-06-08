<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

final class LLMContentParser
{
    private static ?ContentBlockParserRegistry $registry = null;

    /**
     * Normalise an LLM response payload into the canonical
     * `{content, reasoning}` shape that drivers consume.
     *
     * @param string|array<int, mixed>|null $rawContent
     * @return array{content: string, reasoning: string|null}
     */
    public static function parse(string|array|null $rawContent): array
    {
        if (is_string($rawContent)) {
            return self::parseString($rawContent);
        }

        if (!is_array($rawContent)) {
            return ['content' => '', 'reasoning' => null];
        }

        return self::parseBlocks($rawContent);
    }

    /**
     * @return array{content: string, reasoning: string|null}
     */
    private static function parseString(string $rawContent): array
    {
        $extracted = ThinkingTagExtractor::extract($rawContent);

        return [
            'content'   => $extracted['content'],
            'reasoning' => $extracted['reasoning'],
        ];
    }

    /**
     * @param array<int, mixed> $rawContent
     * @return array{content: string, reasoning: string|null}
     */
    private static function parseBlocks(array $rawContent): array
    {
        $textContent = '';
        $reasoning   = null;
        $registry    = self::registry();

        foreach ($rawContent as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type    = (string) ($block['type'] ?? '');
            $parser  = $registry->for($type);

            if ($parser === null) {
                continue;
            }

            $parsed = $parser->parse($block);

            if ($parsed->content !== '') {
                $textContent .= $parsed->content;
            }

            if ($parsed->reasoning !== null) {
                $reasoning = $reasoning === null
                    ? $parsed->reasoning
                    : $reasoning . "\n" . $parsed->reasoning;
            }
        }

        return [
            'content'   => $textContent,
            'reasoning' => $reasoning,
        ];
    }

    private static function registry(): ContentBlockParserRegistry
    {
        return self::$registry ??= new ContentBlockParserRegistry();
    }
}

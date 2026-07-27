<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

/**
 * Splits embedded inline reasoning tags out of a free-form text string.
 *
 * Matches `<think>...</think>`, `<thinking>...</thinking>` and
 * `<thought>...</thought>` blocks, with optional whitespace inside the
 * tag names.
 *
 * {@see self::strip()} keeps the historical "text only" behaviour for
 * callers that don't care about the extracted reasoning (the Anthropic
 * driver relies on provider-signed `thinking` blocks instead). The
 * Anthropic path intentionally drops inline-tag reasoning on the floor;
 * the OpenAI compatible driver uses {@see self::split()} so unsigned
 * reasoning reaches the UI.
 */
final class ThinkingTagExtractor
{
    /**
     * Strip embedded reasoning tags and return the leftover text only.
     */
    public static function strip(string $rawContent): string
    {
        return self::split($rawContent)['text'];
    }

    /**
     * Split a raw text payload into its displayable text and any inline
     * reasoning extracted from the tags. Multiple matches are
     * concatenated with a blank line; whitespace inside and around the
     * extracted blocks is collapsed.
     *
     * @return array{text: string, reasoning: string}
     */
    public static function split(string $rawContent): array
    {
        $reasoningParts = [];
        $cleaned = $rawContent;

        foreach (self::patterns() as $pattern) {
            if (preg_match_all($pattern, $rawContent, $matches) === false) {
                continue;
            }
            foreach ($matches[1] as $body) {
                $reasoningParts[] = self::collapseWhitespace(trim((string) $body));
            }
            $cleaned = preg_replace($pattern, '', $cleaned) ?? $cleaned;
        }

        return [
            'text' => self::collapseWhitespace(trim($cleaned)),
            'reasoning' => implode("\n\n", array_values(array_filter(
                $reasoningParts,
                static fn(string $part): bool => $part !== '',
            ))),
        ];
    }

    private static function collapseWhitespace(string $value): string
    {
        $collapsed = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
        $collapsed = preg_replace('/\n{3,}/', "\n\n", $collapsed) ?? $collapsed;
        return trim($collapsed);
    }

    /**
     * @return list<string>
     */
    private static function patterns(): array
    {
        return [
            '/<\s*think\b[^>]*>(.*?)<\/\s*think\s*>/is',
            '/<\s*thinking\b[^>]*>(.*?)<\/\s*thinking\s*>/is',
            '/<\s*thought\b[^>]*>(.*?)<\/\s*thought\s*>/is',
        ];
    }
}

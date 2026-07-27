<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

/**
 * Splits embedded inline reasoning tags out of a free-form text string.
 *
 * Matches the three tag forms the field uses (and their `<thinking>` /
 * `<thought>` siblings), with optional whitespace inside the tag names.
 *
 * The historical {@see self::strip()} returns text only — useful for
 * the Anthropic path that sources reasoning from provider-signed
 * `thinking` blocks. The OpenAI compatible driver uses
 * {@see self::split()} so unsigned inline reasoning can reach the UI.
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

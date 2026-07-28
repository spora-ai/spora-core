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
     * concatenated with a blank line in source order; whitespace inside
     * and around the extracted blocks is collapsed.
     *
     * @return array{text: string, reasoning: string}
     */
    public static function split(string $rawContent): array
    {
        $reasoningParts = [];

        if (preg_match_all(self::pattern(), $rawContent, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $reasoningParts[] = self::collapseWhitespace(trim((string) $match[2]));
            }
        }

        $cleaned = preg_replace(self::pattern(), '', $rawContent) ?? $rawContent;

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
     * Single alternation regex walks the string in source order so mixed
     * tag types (e.g. `<thought>` then `<\u200Bthink>`) preserve their
     * original sequencing. `\1` backref enforces matching open/close
     * tags so `<\u200Bthink>A</thought>` cannot match.
     */
    private static function pattern(): string
    {
        return '/<\s*(think|thinking|thought)\b[^>]*>(.*?)<\/\s*\1\s*>/is';
    }
}

<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

/**
 * Strips embedded `<think>…</think>` / `<thinking>…</thinking>` /
 * `<thought>…</thought>` reasoning tags from a free-form text string.
 *
 * The extracted reasoning itself is no longer surfaced — operators get
 * reasoning only from providers that return signed `thinking` content
 * blocks (Anthropic extended thinking). Unsigned inline reasoning is
 * silently dropped on the floor. See the
 * `llm-cache-and-reasoning-roundtrip` plan for the full rationale.
 */
final class ThinkingTagExtractor
{
    public static function strip(string $rawContent): string
    {
        $cleaned = $rawContent;

        foreach (self::patterns() as $pattern) {
            if (preg_match_all($pattern, $rawContent, $matches) === false) {
                continue;
            }
            $cleaned = preg_replace($pattern, '', $cleaned) ?? $cleaned;
        }

        // Collapse horizontal whitespace only (preserve newlines) so the
        // cleaned text is readable when the tags wrap multi-line blocks.
        $cleaned = preg_replace('/[ \t]+/', ' ', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    /**
     * @return list<string>
     */
    private static function patterns(): array
    {
        return [
            '#<think>(.*?)</think>#is',
            '/<thinking\b[^>]*>(.*?)<\/thinking>/is',
            '/<thought\b[^>]*>(.*?)<\/thought>/is',
        ];
    }
}

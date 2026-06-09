<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

/**
 * Extracts embedded `<think>…</think>` / `<thinking>…</thinking>` /
 * `<thought>…</thought>` reasoning tags from a free-form text string and
 * returns the cleaned text plus the collected reasoning.
 *
 * Extracted into its own class so both the top-level string parser and
 * `TextBlockParser` can share the same regex-driven logic without
 * duplicating it.
 */
final class ThinkingTagExtractor
{
    /**
     * @return array{content: string, reasoning: string|null}
     */
    public static function extract(string $rawContent): array
    {
        $textContent = $rawContent;

        foreach (self::patterns() as $pattern) {
            if (!preg_match_all($pattern, $rawContent, $matches)) {
                continue;
            }

            $reasoning = implode("\n", array_map('trim', $matches[1]));
            $trimmed   = trim($reasoning);

            // Strip <text>...</text> wrappers and replace with space
            $textContent = preg_replace_callback('/<\/?text[^>]*>/is', static fn(): string => ' ', $textContent);
            // Remove thinking tags
            $textContent = preg_replace($pattern, '', $textContent);
            // Collapse horizontal whitespace only (preserve newlines)
            $textContent = trim(preg_replace('/[ \t]+/', ' ', $textContent));

            return [
                'content'   => $textContent,
                'reasoning' => $trimmed !== '' ? $trimmed : null,
            ];
        }

        return [
            'content'   => $textContent,
            'reasoning' => null,
        ];
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

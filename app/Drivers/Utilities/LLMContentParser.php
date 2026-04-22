<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

final class LLMContentParser
{
    /**
     * @param string|array<int, mixed>|null $rawContent
     * @return array{content: string, reasoning: string|null}
     */
    public static function parse(string|array|null $rawContent): array
    {
        $textContent = '';
        $reasoning   = null;

        if (is_array($rawContent)) {
            // Block array format: [{'type':'text','text':'...'}, {'type':'thinking','thinking':'...'}]
            foreach ($rawContent as $block) {
                if (!is_array($block)) {
                    continue;
                }

                $type = $block['type'] ?? '';

                if ($type === 'text') {
                    $blockText = (string) ($block['text'] ?? '');
                    $parsed = self::parseString($blockText);
                    $textContent .= $parsed['content'];

                    if ($parsed['reasoning'] !== null) {
                        $reasoning = $reasoning === null ? $parsed['reasoning'] : $reasoning . "\n" . $parsed['reasoning'];
                    }
                } elseif ($type === 'thinking') {
                    $blockReasoning = (string) ($block['thinking'] ?? '');
                    $reasoning      = $reasoning === null ? $blockReasoning : $reasoning . "\n" . $blockReasoning;
                } elseif ($type === 'redacted_thinking') {
                    // Anthropic issues redacted_thinking when signature verification fails or reasoning is blocked
                    $blockReasoning = '[Redacted Thinking]';
                    $reasoning      = $reasoning === null ? $blockReasoning : $reasoning . "\n" . $blockReasoning;
                }
            }
        } elseif (is_string($rawContent)) {
            $parsed = self::parseString($rawContent);
            $textContent = $parsed['content'];
            $reasoning   = $parsed['reasoning'];
        }

        return [
            'content'   => $textContent,
            'reasoning' => $reasoning,
        ];
    }

    /**
     * @return array{content: string, reasoning: string|null}
     */
    private static function parseString(string $rawContent): array
    {
        $textContent = '';
        $reasoning   = null;

        // Match <think>...</think> (Anthropic), <thinking>...</thinking>, <thought>...</thought>
        $textContent = $rawContent;
        foreach ([
            '#<think>(.*?)</think>#is',
            '/<thinking\b[^>]*>(.*?)<\/thinking>/is',
            '/<thought\b[^>]*>(.*?)<\/thought>/is',
        ] as $pattern) {
            if (preg_match_all($pattern, $rawContent, $matches)) {
                $reasoning = implode("\n", array_map('trim', $matches[1]));
                // Strip <text>...</text> wrappers and replace with space
                $textContent = preg_replace_callback('/<\/?text[^>]*>/is', static fn(): string => ' ', $textContent);
                // Remove thinking tags
                $textContent = preg_replace($pattern, '', $textContent);
                // Collapse horizontal whitespace only (preserve newlines)
                $textContent = trim(preg_replace('/[ \t]+/', ' ', $textContent));
                break;
            }
        }

        return [
            'content'   => $textContent,
            'reasoning' => $reasoning !== null && trim($reasoning) !== '' ? trim($reasoning) : null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

final class LLMContentParser
{
    /**
     * Parses raw API message content into structured text and reasoning.
     *
     * Handles:
     * - Array of blocks (OpenAI o1/o3, LM Studio)
     * - Array of Anthropic blocks
     * - Strings containing <thinking>...</thinking> XML tags (DeepSeek, open-source models via litellm)
     *
     * @param string|array<int, mixed>|null $rawContent
     * @return array{content: string, reasoning: string|null}
     */
    public static function parse(string|array|null $rawContent): array
    {
        $textContent = '';
        $reasoning   = null;

        if (is_array($rawContent)) {
            // Process blocks (e.g. [{'type':'text','text':'...'}, {'type':'thinking','thinking':'...'}])
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

        // Some providers embed thinking as XML tags inside a plain string.
        if (preg_match_all('/<thinking\b[^>]*>(.*?)<\/thinking>/is', $rawContent, $matches)) {
            $reasoning = implode("\n", array_map('trim', $matches[1]));
            $textContent = trim(
                preg_replace_callback(
                    '/<\/?text[^>]*>/is',
                    static fn(): string => ' ',
                    $rawContent,
                ),
            );
            $textContent = preg_replace('/<thinking\b[^>]*>.*?<\/thinking>/is', '', $textContent);
            $textContent = trim(preg_replace('/\s+/', ' ', $textContent));
        } else {
            $textContent = $rawContent;
        }

        return [
            'content'   => $textContent,
            'reasoning' => $reasoning !== null && trim($reasoning) !== '' ? trim($reasoning) : null,
        ];
    }
}

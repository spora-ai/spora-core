<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * Preserves an OpenAI Responses reasoning item for byte-identical replay.
 *
 * The item is opaque: the provider correlates it by `id` and restores
 * the chain of thought from `encrypted_content`, so both have to survive
 * the round trip through the history unchanged.
 */
final class ReasoningBlockParser implements ContentBlockParser
{
    public function parse(array $block): ParsedContentBlock
    {
        $summary = is_array($block['summary'] ?? null) ? $block['summary'] : [];
        $encrypted = $block['encrypted_content'] ?? null;

        return new ParsedContentBlock(
            contentBlock: ContentBlock::reasoning(
                self::joinSummary($summary),
                is_string($encrypted) ? $encrypted : null,
                ['id' => (string) ($block['id'] ?? ''), 'summary' => $summary],
            ),
        );
    }

    /**
     * The summary is a list of typed text parts; the block carries their
     * text so the chat panel can show what the model reasoned.
     *
     * @param array<int, mixed> $summary
     */
    private static function joinSummary(array $summary): string
    {
        $parts = [];
        foreach ($summary as $part) {
            if (!is_array($part)) {
                continue;
            }
            $text = (string) ($part['text'] ?? '');
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }
}

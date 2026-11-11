<?php

declare(strict_types=1);

namespace Spora\Drivers\OpenAI;

use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * The content-part shape of a Chat Completions message.
 *
 * Strings pass through; content-block lists become OpenAI's content-part
 * format: `{type:"text", text}` and
 * `{type:"image_url", image_url:{url:"data:..." | <url>}}`.
 *
 * Split out of {@see \Spora\Drivers\OpenAICompatibleDriver} so the
 * provider's content shape lives in one place and the driver class stays
 * focused on transport.
 */
final class ChatCompletionsContent
{
    /**
     * Translate a message's `content` to OpenAI's wire shape.
     */
    public static function normalize(mixed $content): mixed
    {
        if ($content === null || is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return (string) $content;
        }
        $parts = [];
        foreach ($content as $b) {
            if (!is_array($b)) {
                continue;
            }
            $part = self::contentBlockToPart($b);
            if ($part !== null) {
                $parts[] = $part;
            }
        }
        return $parts === [] ? '' : $parts;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private static function contentBlockToPart(array $block): ?array
    {
        $type = $block['type'] ?? null;
        return match ($type) {
            ContentBlock::TYPE_TEXT,
            ContentBlock::TYPE_THINKING => ['type' => 'text', 'text' => (string) ($block['text'] ?? '')],
            ContentBlock::TYPE_REDACTED_THINKING => ['type' => 'text', 'text' => '[Redacted Thinking]'],
            ContentBlock::TYPE_IMAGE => self::imageBlockToPart($block),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private static function imageBlockToPart(array $block): ?array
    {
        if (isset($block['base64']) && is_string($block['base64']) && $block['base64'] !== '' && isset($block['mediaType'])) {
            return [
                'type'     => 'image_url',
                'image_url' => ['url' => 'data:' . $block['mediaType'] . ';base64,' . $block['base64']],
            ];
        }
        if (isset($block['url']) && is_string($block['url']) && $block['url'] !== '') {
            return [
                'type'     => 'image_url',
                'image_url' => ['url' => $block['url']],
            ];
        }

        return null;
    }
}

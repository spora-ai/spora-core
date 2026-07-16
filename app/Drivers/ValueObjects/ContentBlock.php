<?php

declare(strict_types=1);

namespace Spora\Drivers\ValueObjects;

use InvalidArgumentException;

/**
 * One content block in a multi-modal message.
 *
 * A `ContentBlock` is the smallest unit a driver serializes to its
 * provider. We currently emit two flavors:
 *
 *   - `text`  — `text` is the rendered text.
 *   - `image` — `mediaType` + `base64` (or `url`) describes a
 *               single image attached to the message.
 *
 * The carrier on the wire is provider-specific — `OpenAICompatibleDriver`
 * emits `image_url` blocks, `AnthropicCompatibleDriver` emits
 * `image` blocks with a `source: {type, media_type, data|url}` shape.
 * Each driver is responsible for the translation; this VO is the
 * intermediate representation the orchestrator / history builder
 * produce and the drivers consume.
 */
final readonly class ContentBlock
{
    public const TYPE_TEXT  = 'text';
    public const TYPE_IMAGE = 'image';

    public function __construct(
        public string  $type,
        public ?string $text = null,
        public ?string $mediaType = null,
        public ?string $base64 = null,
        public ?string $url = null,
    ) {
        if ($type !== self::TYPE_TEXT && $type !== self::TYPE_IMAGE) {
            throw new InvalidArgumentException("Unknown content block type: {$type}");
        }
    }

    public static function text(string $text): self
    {
        return new self(self::TYPE_TEXT, text: $text);
    }

    public static function imageBase64(string $mediaType, string $base64): self
    {
        return new self(self::TYPE_IMAGE, mediaType: $mediaType, base64: $base64);
    }

    public static function imageUrl(string $url): self
    {
        return new self(self::TYPE_IMAGE, url: $url);
    }
}

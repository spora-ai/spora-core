<?php

declare(strict_types=1);

namespace Spora\Drivers\ValueObjects;

use Spora\Drivers\Exceptions\UnknownContentBlockTypeException;

/**
 * Provider-neutral content block with lossless Anthropic reasoning replay.
 *
 * Anthropic requires `thinking` text and its opaque `signature` to be replayed
 * byte-identically on the next turn; mutating either breaks chain continuity.
 * `redacted_thinking.data` is a distinct encrypted payload that clients cannot
 * decrypt. `metadata` preserves provider sub-fields until one becomes
 * load-bearing enough to promote to a typed property.
 */
final readonly class ContentBlock
{
    public const TYPE_TEXT = 'text';
    public const TYPE_IMAGE = 'image';
    public const TYPE_THINKING = 'thinking';
    public const TYPE_REDACTED_THINKING = 'redacted_thinking';
    public const TYPE_TOOL_USE = 'tool_use';

    private const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_IMAGE,
        self::TYPE_THINKING,
        self::TYPE_REDACTED_THINKING,
        self::TYPE_TOOL_USE,
    ];

    /**
     * @param array<string, mixed>|null $toolInput
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public string $type,
        public ?string $text = null,
        public ?string $signature = null,
        public ?string $data = null,
        public ?string $mediaType = null,
        public ?string $base64 = null,
        public ?string $url = null,
        public ?string $toolUseId = null,
        public ?string $toolName = null,
        public ?array $toolInput = null,
        public ?array $metadata = null,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new UnknownContentBlockTypeException($type);
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

    /**
     * The signature is opaque provider state and must be replayed unchanged.
     *
     * @param array<string, mixed>|null $metadata
     */
    public static function thinking(string $text, string $signature, ?array $metadata = null): self
    {
        return new self(self::TYPE_THINKING, text: $text, signature: $signature, metadata: $metadata);
    }

    /**
     * `data` is the opaque encrypted payload, distinct from `signature`.
     */
    public static function redactedThinking(string $data): self
    {
        return new self(self::TYPE_REDACTED_THINKING, data: $data);
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function toolUse(string $id, string $name, array $input): self
    {
        return new self(self::TYPE_TOOL_USE, toolUseId: $id, toolName: $name, toolInput: $input);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'text' => $this->text,
            'signature' => $this->signature,
            'data' => $this->data,
            'mediaType' => $this->mediaType,
            'base64' => $this->base64,
            'url' => $this->url,
            'toolUseId' => $this->toolUseId,
            'toolName' => $this->toolName,
            'toolInput' => $this->toolInput,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new UnknownContentBlockTypeException($type);
        }

        return new self(
            type: $type,
            text: self::nullableString($data['text'] ?? null),
            signature: self::nullableString($data['signature'] ?? null),
            data: self::nullableString($data['data'] ?? null),
            mediaType: self::nullableString($data['mediaType'] ?? $data['media_type'] ?? null),
            base64: self::nullableString($data['base64'] ?? null),
            url: self::nullableString($data['url'] ?? null),
            toolUseId: self::nullableString($data['toolUseId'] ?? $data['tool_use_id'] ?? null),
            toolName: self::nullableString($data['toolName'] ?? $data['tool_name'] ?? null),
            toolInput: is_array($data['toolInput'] ?? $data['tool_input'] ?? null)
                ? ($data['toolInput'] ?? $data['tool_input'])
                : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}

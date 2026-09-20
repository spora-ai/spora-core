<?php

declare(strict_types=1);

namespace Spora\Todo;

use Carbon\CarbonImmutable;
use Throwable;

final readonly class TodoState
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public int $version,
        public array $items,
        public ?CarbonImmutable $updatedAt,
    ) {}

    public static function empty(): self
    {
        return new self(self::SCHEMA_VERSION, [], null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach (($data['items'] ?? []) as $i => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $items[] = TodoItem::fromLlmInput($raw, is_int($i) ? $i : 0);
        }

        $updatedAtRaw = $data['updated_at'] ?? null;
        $updatedAt = null;
        if (is_string($updatedAtRaw) && $updatedAtRaw !== '') {
            try {
                $updatedAt = CarbonImmutable::parse($updatedAtRaw);
            } catch (Throwable) {
                $updatedAt = null;
            }
        }

        return new self(
            version: (int) ($data['version'] ?? self::SCHEMA_VERSION),
            items: $items,
            updatedAt: $updatedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version'    => $this->version,
            'items'      => array_map(static fn(TodoItem $i): array => $i->toArray(), $this->items),
            'updated_at' => $this->updatedAt?->toIso8601String(),
        ];
    }

    public function getActiveItem(): ?TodoItem
    {
        foreach ($this->items as $item) {
            if ($item->status === TodoItemStatus::InProgress) {
                return $item;
            }
        }
        return null;
    }
}

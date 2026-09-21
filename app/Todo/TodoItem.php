<?php

declare(strict_types=1);

namespace Spora\Todo;

final readonly class TodoItem
{
    public const CONTENT_MAX = 500;

    public const ACTIVE_FORM_MAX = 200;

    public const SLUG_MAX = 80;

    public function __construct(
        public ?string $id,
        public string $content,
        public ?string $activeForm,
        public TodoItemStatus $status,
        public int $order,
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public static function fromLlmInput(array $input, int $order, string $idPrefix = 't_'): self
    {
        $content = self::cap((string) ($input['content'] ?? ''), self::CONTENT_MAX);
        $activeFormRaw = $input['activeForm'] ?? $input['active_form'] ?? null;
        $activeForm = $activeFormRaw === null || $activeFormRaw === ''
            ? null
            : self::cap((string) $activeFormRaw, self::ACTIVE_FORM_MAX);

        $statusRaw = (string) ($input['status'] ?? 'pending');
        $status = TodoItemStatus::tryFrom($statusRaw) ?? TodoItemStatus::Pending;

        $idRaw = isset($input['id']) && (string) $input['id'] !== ''
            ? (string) $input['id']
            : $idPrefix . bin2hex(random_bytes(8));

        return new self(
            id: $idRaw,
            content: $content,
            activeForm: $activeForm,
            status: $status,
            order: $order,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'content'    => $this->content,
            'activeForm' => $this->activeForm,
            'status'     => $this->status->value,
            'order'      => $this->order,
        ];
    }

    /**
     * Derive a deterministic ASCII slug from the item's content. Used as
     * the default `id` when the model does not supply one on `add`, so the
     * tool produces stable, model-readable handles ("run-the-migration")
     * instead of opaque hex tokens the model can't easily reference.
     */
    public static function slug(string $content): string
    {
        $stripped = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($content))) ?? '';
        $stripped = trim($stripped, '-');
        if ($stripped === '') {
            return 'item';
        }
        if (strlen($stripped) > self::SLUG_MAX) {
            $stripped = rtrim(substr($stripped, 0, self::SLUG_MAX), '-');
        }
        return $stripped === '' ? 'item' : $stripped;
    }

    private static function cap(string $value, int $max): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (mb_strlen($trimmed, 'UTF-8') <= $max) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $max - 1, 'UTF-8') . '…';
    }
}

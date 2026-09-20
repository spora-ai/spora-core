<?php

declare(strict_types=1);

namespace Spora\Tools;

final readonly class PendingQuestion
{
    public const HEADER_MAX = 30;

    public const OPTIONS_MIN = 2;

    public const OPTIONS_MAX = 4;

    public function __construct(
        public string $question,
        public string $header,
        public array $options,
        public bool $multiple,
        public bool $allowFreeText,
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public static function fromLlmInput(array $input): self
    {
        $options = [];
        foreach (($input['options'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $options[] = [
                'label'       => (string) ($raw['label'] ?? ''),
                'description' => isset($raw['description']) ? (string) $raw['description'] : null,
                'preview'     => isset($raw['preview']) ? (string) $raw['preview'] : null,
            ];
        }

        return new self(
            question: trim((string) ($input['question'] ?? '')),
            header: trim((string) ($input['header'] ?? '')),
            options: $options,
            multiple: (bool) ($input['multiple'] ?? false),
            allowFreeText: (bool) ($input['allowFreeText'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'question'      => $this->question,
            'header'        => $this->header,
            'options'       => $this->options,
            'multiple'      => $this->multiple,
            'allowFreeText' => $this->allowFreeText,
        ];
    }
}

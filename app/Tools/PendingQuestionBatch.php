<?php

declare(strict_types=1);

namespace Spora\Tools;

final readonly class PendingQuestionBatch
{
    public const QUESTIONS_MIN = 1;

    public const QUESTIONS_MAX = 4;

    public function __construct(
        public string $toolCallId,
        public array $questions,
        public string $createdAt,
    ) {}

    /**
     * @param list<PendingQuestion> $questions
     */
    public static function build(string $toolCallId, array $questions): self
    {
        return new self(
            toolCallId: $toolCallId,
            questions: $questions,
            createdAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tool_call_id' => $this->toolCallId,
            'questions'    => array_map(static fn(PendingQuestion $q): array => $q->toApiArray(), $this->questions),
            'created_at'   => $this->createdAt,
        ];
    }
}

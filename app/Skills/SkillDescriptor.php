<?php

declare(strict_types=1);

namespace Spora\Skills;

/**
 * Detail-shaped view of a skill: everything {@see SkillSummary} carries, plus
 * the body, the sidecar listing, and any warnings.
 *
 * Only `SkillProviderInterface::getSkillDetails()` returns one. The body is
 * deliberately absent from {@see SkillSummary} so a list fetch stays cheap.
 */
final readonly class SkillDescriptor
{
    /**
     * @param list<array{path: string, bytes: int}>                 $files
     * @param array<string, string>                                 $metadata
     * @param list<array{code: string, severity: string, message: string, path?: string}> $warnings
     */
    public function __construct(
        public SkillSummary $summary,
        public string $body = '',
        public ?string $compatibility = null,
        public ?string $allowedTools = null,
        public array $metadata = [],
        public array $files = [],
        public array $warnings = [],
    ) {}

    public function name(): string
    {
        return $this->summary->name;
    }

    public function bodyBytes(): int
    {
        return strlen($this->body);
    }
}

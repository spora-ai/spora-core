<?php

declare(strict_types=1);

namespace Spora\Skills;

/**
 * List-shaped view of a skill. Deliberately carries no body: the
 * `allowed_skills` multi-select loads every visible skill at once, so a body
 * here would be tens of kilobytes per row in a dropdown.
 *
 * @see SkillDescriptor for the detail view
 */
final readonly class SkillSummary
{
    public function __construct(
        public string $name,
        public string $description,
        public ?string $license = null,
        public ?string $source = null,
        public ?string $slug = null,
        public int $fileCount = 0,
        public bool $hasWarnings = false,
    ) {}
}

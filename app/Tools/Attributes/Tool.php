<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;

/**
 * Applied at class level. Provides the LLM-facing tool name and description.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Tool
{
    private const NAME_REGEX = '/^[a-z][a-z0-9_]*$/';

    public function __construct(
        /** snake_case, e.g. "tavily_search" — must match /^[a-z][a-z0-9_]*$/ */
        public readonly string $name,
        /** Sent to LLM as function description */
        public readonly string $description,
        /** Human-readable name for UI display, e.g. "Tavily Search". Falls back to name if omitted. */
        public readonly ?string $displayName = null,
        /** Category for grouping tools in the Settings UI, e.g. "research", "communication". Falls back to "general". */
        public readonly string $category = 'general',
    ) {
        if (!preg_match(self::NAME_REGEX, $this->name)) {
            throw new \InvalidArgumentException(
                "Tool name '{$this->name}' must match /^[a-z][a-z0-9_]*$/ (snake_case, lowercase alphanumeric + underscore).",
            );
        }
    }
}

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
    public function __construct(
        /** snake_case, e.g. "tavily_search" — used in URLs */
        public readonly string $name,
        /** Sent to LLM as function description */
        public readonly string $description,
        /** Human-readable name for UI display, e.g. "Tavily Search". Falls back to name if omitted. */
        public readonly ?string $displayName = null,
    ) {}
}

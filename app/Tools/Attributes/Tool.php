<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Marks a class as a Tool the agent can invoke.
 *
 * Usage:
 *   #[Tool(name: 'my_tool', description: 'Does something useful')]
 *   final class MyTool implements ToolInterface { ... }
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
        /**
         * Bundled-icon key (or full SVG / raw path) for the dashboard's tool row.
         * Optional. Resolution chain (see {@see \Spora\Services\ToolIconResolver}):
         *   1. tool.icon (this attribute — most specific)
         *   2. owning plugin's plugin.json icon field (per-plugin default)
         *   3. null on the wire; frontend's <Icon> component falls back to 'puzzle'.
         *
         * Same surface as plugin.json's icon field — accepts bundled names
         * (e.g. 'calendar', 'mail', 'search'), full <svg> strings, or raw
         * path 'd:' strings.
         */
        public readonly ?string $icon = null,
    ) {
        if (!preg_match(self::NAME_REGEX, $this->name)) {
            throw new InvalidArgumentException(
                "Tool name '{$this->name}' must match /^[a-z][a-z0-9_]*$/ (snake_case, lowercase alphanumeric + underscore).",
            );
        }
    }
}

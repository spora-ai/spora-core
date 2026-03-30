<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;

/**
 * Applied zero-or-more times at class level (repeatable).
 * Describes a UI-configurable setting stored in tool_configurations or agent_tool_overrides.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ToolSetting
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        /** "text"|"password"|"select"|"toggle" */
        public readonly string $type,
        public readonly string $description = '',
        public readonly mixed  $default     = null,
        public readonly bool   $required    = false,
        /**
         * "global"  — can only be set in global tool configuration.
         * "agent"   — can be overridden per-agent via agent_tool_overrides.
         */
        public readonly string $scope   = 'agent',
        /** @var array<string, string> key => label pairs. Only used when type === "select". */
        public readonly array  $options = [],
    ) {}
}

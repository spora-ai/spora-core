<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;

/**
 * Applied zero-or-more times at class level (repeatable).
 * Each instance describes one parameter the tool accepts.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ToolParameter
{
    public function __construct(
        public readonly string $name,
        /** JSON Schema primitive: "string"|"number"|"boolean"|"array"|"object" */
        public readonly string $type,
        public readonly string $description,
        public readonly bool   $required = true,
        public readonly mixed  $default  = null,
        /** @var list<string> Only used when type === "string" and values are constrained */
        public readonly array  $enum     = [],
    ) {}
}

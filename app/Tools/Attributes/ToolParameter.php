<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;

/**
 * Describes a parameter the tool accepts, for LLM-facing schema generation.
 *
 * Usage:
 *   #[ToolParameter(name: 'query', type: 'string', description: 'Search term', required: true)]
 *   #[ToolParameter(name: 'limit', type: 'number', description: 'Max results', required: false, default: 10)]
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

<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Describes a parameter the tool accepts, for LLM-facing schema generation.
 *
 * Read by ToolParameterSchemaBuilder via reflection to construct the JSON Schema
 * `properties` object. Declaration order on the class is significant: it determines
 * both the LLM payload property order and the render order in the approval UI.
 *
 * Do NOT declare a `#[ToolParameter(name: 'action', ...)]` when the tool also has
 * `#[ToolOperation]` declarations — the builder synthesizes the discriminator
 * property automatically from the operation declarations.
 *
 * Usage:
 *   #[ToolParameter(name: 'query', type: 'string', description: 'Search term', required: true)]
 *   #[ToolParameter(name: 'limit', type: 'integer', description: 'Max results', required: false, default: 10)]
 *   #[ToolParameter(name: 'days', type: 'integer', description: 'Forecast days', minimum: 1, maximum: 3)]
 *   #[ToolParameter(name: 'date', type: 'string', description: 'Date filter', format: 'date')]
 *   #[ToolParameter(name: 'tags', type: 'array', description: 'Tag list', items: ['type' => 'string'])]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ToolParameter
{
    /** JSON Schema primitives accepted by the LLM function-calling specs. */
    private const ALLOWED_TYPES = ['string', 'number', 'integer', 'boolean', 'array', 'object'];

    public function __construct(
        public readonly string $name,
        /** JSON Schema primitive: "string"|"number"|"integer"|"boolean"|"array"|"object" */
        public readonly string $type,
        public readonly string $description,
        public readonly bool   $required = true,
        public readonly mixed  $default  = null,
        /** @var list<string> Only used when type === "string" and values are constrained */
        public readonly array  $enum     = [],
        /** Lower bound for numeric types. Emitted as JSON Schema `minimum`. */
        public readonly int|float|null $minimum = null,
        /** Upper bound for numeric types. Emitted as JSON Schema `maximum`. */
        public readonly int|float|null $maximum = null,
        /** JSON Schema format hint (e.g. "date", "date-time", "email", "uri"). */
        public readonly ?string $format = null,
        /**
         * Sub-schema for array items, e.g. ['type' => 'string'].
         * Only used when type === "array".
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $items = null,
    ) {
        if (!in_array($this->type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                "ToolParameter '{$this->name}': type '{$this->type}' is not a JSON Schema primitive. "
                . 'Allowed: ' . implode(', ', self::ALLOWED_TYPES) . '.',
            );
        }
    }
}

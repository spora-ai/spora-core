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
 *   #[ToolParameter(name: 'query',     type: 'string',  description: 'Search term', required: true)]
 *   #[ToolParameter(name: 'limit',     type: 'integer', description: 'Max results', required: false, default: 10)]
 *   #[ToolParameter(name: 'days',      type: 'integer', description: 'Forecast days', minimum: 1, maximum: 3)]
 *   #[ToolParameter(name: 'date',      type: 'string',  description: 'Date filter', format: 'date')]
 *   #[ToolParameter(name: 'tags',      type: 'array',   description: 'Tag list', items: ['type' => 'string'])]
 *   #[ToolParameter(name: 'epoch',     type: 'integer', description: 'Unix ts',   required: ['format'])]
 *
 * Per-op required: pass a list of operation names (`required: ['format']`) to
 * mark a parameter as required only when one of those operations is in the
 * agent's allowed-ops set. `required: true` / `required: false` keep the
 * global behaviour. `required: []` is treated as `required: true` (no
 * allowed-op can satisfy "empty"); use `required: false` for the truly
 * optional case.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ToolParameter
{
    private const ALLOWED_TYPES = ['string', 'number', 'integer', 'boolean', 'array', 'object'];

    /** @var bool|list<string> */
    public readonly bool|array $required;

    /**
     * @param bool|list<string> $required true = required for any op, false = always optional,
     *                                  list (non-empty) = required only when dispatcher is one of those ops.
     *                                  Empty list `[]` is coerced to `true`; use `false` for optional.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $description,
        bool|array $required = true,
        public readonly mixed $default = null,
        /** @var list<string> */
        public readonly array $enum = [],
        public readonly int|float|null $minimum = null,
        public readonly int|float|null $maximum = null,
        public readonly ?string $format = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $items = null,
    ) {
        if (!in_array($this->type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                "ToolParameter '{$this->name}': type '{$this->type}' is not a JSON Schema primitive. "
                . 'Allowed: ' . implode(', ', self::ALLOWED_TYPES) . '.',
            );
        }
        if (is_array($required)) {
            foreach ($required as $op) {
                if ($op === '') {
                    throw new InvalidArgumentException(
                        "ToolParameter '{$this->name}': required list entries must be non-empty operation names.",
                    );
                }
            }
            if ($required === []) {
                $required = true;
            }
        }
        $this->required = $required;
    }
}

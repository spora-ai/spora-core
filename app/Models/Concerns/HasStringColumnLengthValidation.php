<?php

declare(strict_types=1);

namespace Spora\Models\Concerns;

use Spora\Services\MaxLengthValidator;

/**
 * Shared `save()` override + `assertStringColumnsFit()` body for any Eloquent
 * model with bounded string columns. Pair with a `STRING_COLUMN_MAX_LENGTHS`
 * const and a {@see stringColumnsFitContext()} implementation on the using
 * class.
 *
 * Lives here (not on `Model` directly) so the boundary remains opt-in: a
 * model picks it up by declaring the const + the two labels, no base
 * class to inherit and no runtime introspection.
 */
trait HasStringColumnLengthValidation
{
    /**
     * Labels the helper embeds in the {@see \InvalidArgumentException}
     * message: `[rowLabel, originLabel]`. `rowLabel` is the human-readable
     * table name (`"tool_calls"`); `originLabel` is the row's debugging
     * anchor (an id, a class name, etc.).
     *
     * @return array{0: string, 1: string}
     */
    abstract protected function stringColumnsFitContext(): array;

    /**
     * Override pattern (instead of `static::saving` in `booted()`):
     * Spora's standalone Capsule never wires an EventDispatcher into
     * `Model::$dispatcher`, so static listeners silently never fire.
     * Same constraint that drove {@see \Spora\Models\Principal::save()}.
     */
    public function save(array $options = []): bool
    {
        $this->assertStringColumnsFit();
        return parent::save($options);
    }

    /**
     * Public so the bulk insert path can validate rows in tests without
     * round-tripping through Eloquent (same pattern as
     * {@see \Spora\Models\Principal::validateXor()}).
     */
    public function assertStringColumnsFit(): void
    {
        [$rowLabel, $originLabel] = $this->stringColumnsFitContext();
        MaxLengthValidator::assertFits(
            $this->attributes,
            static::STRING_COLUMN_MAX_LENGTHS,
            $rowLabel,
            $originLabel,
        );
    }
}

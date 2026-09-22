<?php

declare(strict_types=1);

namespace Spora\Models\Concerns;

use Spora\Services\MaxLengthValidator;

/**
 * Shared `save()` override + `assertStringColumnsFit()` body for any
 * Eloquent model with bounded string columns. Pair with a
 * `STRING_COLUMN_MAX_LENGTHS` const and a {@see stringColumnsFitContext()}
 * implementation. Opt-in via `use`, no base class to inherit.
 */
trait HasStringColumnLengthValidation
{
    /** @return array{0: string, 1: string} */
    abstract protected function stringColumnsFitContext(): array;

    /** Spora's standalone Capsule never wires an EventDispatcher, so `save()` override is the only path that fires. */
    public function save(array $options = []): bool
    {
        $this->assertStringColumnsFit();
        return parent::save($options);
    }

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

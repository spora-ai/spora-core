<?php

declare(strict_types=1);

namespace Spora\Settings;

/**
 * One stored setting, already decoded.
 *
 * `undecryptable` is the whole reason this is a DTO rather than the raw row:
 * the overlay must be able to tell "not configured" from "configured with a
 * value I cannot read", and it must be able to tell them apart without
 * catching an exception per row at every call site.
 */
final readonly class SettingsRow
{
    public function __construct(
        public string $group,
        public string $key,
        public mixed $value,
        public bool $encrypted,
        public ?int $updatedBy,
        public bool $undecryptable = false,
    ) {
    }
}
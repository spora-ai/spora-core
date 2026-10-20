<?php

declare(strict_types=1);

namespace Spora\Build\Catalog;

/**
 * One kind of operator-facing literal, and where each one is written.
 *
 * The shape is a list of pairs rather than a map because the same message
 * is often written in more than one place, and every one of those places
 * is worth knowing: the locator is what the failure output points at.
 */
interface Harvest
{
    /**
     * @return list<array{message: string, locator: string}>
     */
    public function harvest(): array;
}

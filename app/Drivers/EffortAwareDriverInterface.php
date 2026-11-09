<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * The optional capability a driver implements when it can express a
 * reasoning-effort level.
 *
 * Deliberately separate from {@see LLMDriverInterface}: that one is a
 * published contract, and adding a method to it would break every
 * implementor — including out-of-tree ones.
 */
interface EffortAwareDriverInterface
{
    /**
     * The canonical effort levels this driver can express, mapped to the
     * value the provider expects.
     *
     * @return array<string, string> canonical level value => provider wire value
     */
    public function effortLevels(): array;
}

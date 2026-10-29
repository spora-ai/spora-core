<?php

declare(strict_types=1);

namespace Spora\Scheduling;

/**
 * One unit of plugin work.
 *
 * A value object, not a row: the provider that produced it recognises the
 * payload, and core hands the unit back to the provider that reported it.
 */
final readonly class DueWork
{
    /**
     * @param string               $provider    The provider class name, as the provider spells it. Core logs it; the routing is on the provider that reported the unit.
     * @param int                  $principalId The principal the work belongs to — core checks it against the caller's scope.
     * @param array<string, mixed> $payload     The provider's own data; core does not read it.
     * @param string               $dueAt       `Y-m-d H:i:s` in UTC.
     */
    public function __construct(
        public string $provider,
        public int $principalId,
        public array $payload,
        public string $dueAt,
    ) {}
}

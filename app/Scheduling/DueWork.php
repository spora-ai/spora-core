<?php

declare(strict_types=1);

namespace Spora\Scheduling;

/**
 * One claimed unit of plugin work.
 *
 * A value object, not a row: the provider that produced it recognises the
 * payload, and core only routes the work back to the provider named by
 * {@see self::$provider}.
 */
final readonly class DueWork
{
    /**
     * @param string               $provider    The provider class name — core routes on it.
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

<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use InvalidArgumentException;

/**
 * Static registry of {@see MediaDerivativeProducerInterface} FQCNs.
 *
 * Mirrors {@see MediaConverterDiscovery} exactly so plugin authors only
 * learn one registration pattern. PHP-DI v7 does not expose a runtime
 * taggable container, so a static list populated by core in
 * {@see \Spora\Core\ContainerDefinitions} and by plugins in their
 * `register(ContainerBuilder)` hook is the bridge between the two.
 */
final class MediaDerivativeProducerDiscovery
{
    /** @var list<class-string<MediaDerivativeProducerInterface>> */
    private static array $producers = [];

    /**
     * Add a producer class to the registry. Idempotent: adding the
     * same FQCN twice is a no-op (no duplicates).
     *
     * @param class-string<MediaDerivativeProducerInterface> $class
     */
    public static function add(string $class): void
    {
        if (!is_subclass_of($class, MediaDerivativeProducerInterface::class)) {
            throw new InvalidArgumentException(sprintf(
                'MediaDerivativeProducerDiscovery::add: %s does not implement %s',
                $class,
                MediaDerivativeProducerInterface::class,
            ));
        }
        if (!in_array($class, self::$producers, true)) {
            self::$producers[] = $class;
        }
    }

    /**
     * @return list<class-string<MediaDerivativeProducerInterface>>
     */
    public static function all(): array
    {
        return self::$producers;
    }

    /**
     * Test-only: clear the registry between test runs.
     */
    public static function reset(): void
    {
        self::$producers = [];
    }
}

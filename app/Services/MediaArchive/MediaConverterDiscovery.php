<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

/**
 * Static registry of {@see MediaConverterInterface} FQCNs.
 *
 * PHP-DI v7 does not ship a runtime queryable tag store the way v6 did,
 * so this class is the bridge: core converters self-register in
 * {@see \Spora\Core\ContainerDefinitions}, and plugins add their own
 * from their `register(ContainerBuilder)` hook. The
 * {@see MediaConverterRegistry} reads this list at construction time
 * and instantiates each class via the container.
 *
 * Order matters: the registry resolves the first converter whose
 * `supportedMimeTypes()` matches the asset's MIME, then the first
 * whose `supportedExtensions()` matches the filename extension. Add
 * more specific converters BEFORE generic ones.
 */
final class MediaConverterDiscovery
{
    /** @var list<class-string<MediaConverterInterface>> */
    private static array $converters = [];

    /**
     * Add a converter class to the registry. Idempotent: adding the
     * same FQCN twice is a no-op (no duplicates).
     *
     * @param class-string<MediaConverterInterface> $class
     */
    public static function add(string $class): void
    {
        if (!is_subclass_of($class, MediaConverterInterface::class)) {
            throw new \InvalidArgumentException(sprintf(
                'MediaConverterDiscovery::add: %s does not implement %s',
                $class,
                MediaConverterInterface::class,
            ));
        }
        if (!in_array($class, self::$converters, true)) {
            self::$converters[] = $class;
        }
    }

    /**
     * @return list<class-string<MediaConverterInterface>>
     */
    public static function all(): array
    {
        return self::$converters;
    }

    /**
     * Test-only: clear the registry between test runs.
     */
    public static function reset(): void
    {
        self::$converters = [];
    }
}

<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Services\MediaArchive\Concerns\DiscoversRegistrations;

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
    /** @use DiscoversRegistrations<MediaConverterInterface> */
    use DiscoversRegistrations;

    /**
     * @return class-string<MediaConverterInterface>
     */
    protected static function registrationContract(): string
    {
        return MediaConverterInterface::class;
    }
}

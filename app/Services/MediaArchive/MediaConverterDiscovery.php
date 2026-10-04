<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Services\MediaArchive\Concerns\DiscoversRegistrations;

/**
 * Static registry of {@see MediaConverterInterface} FQCNs, read by
 * {@see MediaConverterRegistry} at construction.
 *
 * Order matters: the registry resolves the first converter whose
 * `supportedMimeTypes()` matches the asset's MIME, then the first whose
 * `supportedExtensions()` matches the filename extension. Add more specific
 * converters BEFORE generic ones.
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

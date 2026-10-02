<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Services\MediaArchive\Concerns\DiscoversRegistrations;

/**
 * Static registry of {@see MediaDerivativeProducerInterface} FQCNs.
 *
 * Mirrors {@see MediaConverterDiscovery} exactly so plugin authors only
 * learn one registration pattern — the shared body now lives in
 * {@see DiscoversRegistrations} rather than being copy-pasted. PHP-DI
 * v7 does not expose a runtime taggable container, so a static list
 * populated by core in {@see \Spora\Core\ContainerDefinitions} and by
 * plugins in their `register(ContainerBuilder)` hook is the bridge
 * between the two.
 */
final class MediaDerivativeProducerDiscovery
{
    /** @use DiscoversRegistrations<MediaDerivativeProducerInterface> */
    use DiscoversRegistrations;

    /**
     * @return class-string<MediaDerivativeProducerInterface>
     */
    protected static function registrationContract(): string
    {
        return MediaDerivativeProducerInterface::class;
    }
}

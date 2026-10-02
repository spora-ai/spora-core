<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

interface MediaMimeRefinerInterface
{
    /**
     * Upgrade a coarse or wrong sniffed MIME to a more specific one.
     * Returns null to decline. Pure, never throws.
     *
     * Implementations MUST be constructible without arguments:
     * {@see MimeSniffer} instantiates them statically, which is what
     * keeps {@see MimeSniffer}'s own constructor argument-free — it is
     * `new MimeSniffer()`-ed at every test call site and bound with
     * `new MimeSniffer()` in {@see \Spora\Core\ContainerDefinitions}.
     * Same reasoning as {@see Producers\ImageDerivativeProducer}'s
     * documented no-arg constructor: the consumers of a static registry
     * have no container to resolve a refiner's collaborators with.
     */
    public function refine(string $bytes, ?string $filename, string $sniffedMime): ?string;
}

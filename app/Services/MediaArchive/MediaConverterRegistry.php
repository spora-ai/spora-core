<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Psr\Container\ContainerInterface;

/**
 * Resolves a {@see MediaConverterInterface} for a given asset.
 *
 * The registry reads its converter list from {@see MediaConverterDiscovery}
 * (a static list populated by core in {@see \Spora\Core\ContainerDefinitions}
 * and by plugins in their `register(ContainerBuilder)` hook). Each FQCN is
 * instantiated via the DI container so constructor dependencies (e.g. the
 * PDF parser facade) are resolved normally.
 *
 * Resolution order in {@see findFor()}:
 *   1. First converter whose `supportedMimeTypes()` contains the asset's MIME.
 *   2. First converter whose `supportedExtensions()` matches the filename extension.
 *   3. `null` — no converter handles this asset; the upload pipeline leaves
 *      `markdown_content` NULL and the upload still succeeds.
 */
final class MediaConverterRegistry
{
    /** @var list<MediaConverterInterface> */
    private readonly array $converters;

    public function __construct(ContainerInterface $container)
    {
        $instances = [];
        foreach (MediaConverterDiscovery::all() as $class) {
            /** @var MediaConverterInterface $instance */
            $instance = $container->get($class);
            $instances[] = $instance;
        }
        $this->converters = $instances;
    }

    public function findFor(string $mime, ?string $filename): ?MediaConverterInterface
    {
        $mime = strtolower($mime);

        foreach ($this->converters as $converter) {
            if (in_array($mime, array_map('strtolower', $converter->supportedMimeTypes()), true)) {
                return $converter;
            }
        }

        if ($filename !== null) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext !== '') {
                foreach ($this->converters as $converter) {
                    if (in_array($ext, array_map('strtolower', $converter->supportedExtensions()), true)) {
                        return $converter;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Convenience: resolve a converter and run it, returning `null` when no
     * converter handles the asset. Caller catches `Throwable` from
     * `toMarkdown()`.
     */
    public function convert(string $bytes, string $mime, ?string $filename): ?string
    {
        $converter = $this->findFor($mime, $filename);
        if ($converter === null) {
            return null;
        }

        return $converter->toMarkdown($bytes, $mime, $filename);
    }

    /**
     * @return list<string> Flat list of every supported MIME type across all
     *                      registered converters. Used by
     *                      {@see MediaAllowedTypesService} to compute the
     *                      upload allowlist at request time.
     */
    public function allSupportedMimeTypes(): array
    {
        $mimes = [];
        foreach ($this->converters as $converter) {
            foreach ($converter->supportedMimeTypes() as $mime) {
                $mimes[strtolower($mime)] = true;
            }
        }
        return array_keys($mimes);
    }
}

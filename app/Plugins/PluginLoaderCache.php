<?php

declare(strict_types=1);

namespace Spora\Plugins;

use JsonException;

/**
 * Manifest discovery + stamp cache for PluginLoader.
 *
 * Owns the boot-time concern of "what plugins are out there and have any of
 * them changed since last boot?" — separate from the loader's responsibility
 * of "how do I instantiate a plugin once I have its manifest".
 *
 * The cache is a sha256 hash of every discovered manifest (path, mtime,
 * content hash) written to $stampPath after a successful boot. On a cache
 * hit, the loader re-instantiates plugins from the sidecar JSON without
 * re-parsing or re-validating the manifest, and without calling each
 * plugin's `register()` hook. The boot path still does a full directory
 * scan to compute the stamp hash; what the cache saves is the more
 * expensive parse + autoload-register + instantiate cycle.
 */
final class PluginLoaderCache
{
    /** Stamp file suffix for the sidecar JSON cache. */
    private const SIDECAR_SUFFIX = '.cache.json';

    /**
     * @param list<string> $pluginDirectories Absolute paths to scan.
     * @param ?string      $stampPath         Stamp file path. Null disables caching.
     */
    public function __construct(
        private readonly array $pluginDirectories,
        private readonly ?string $stampPath = null,
    ) {}

    /**
     * Scan every configured directory and return a deterministic, sorted map
     * of `absolute_manifest_path => {path, contents}` entries.
     *
     * @return array<string, array{path: string, contents: string}>
     */
    public function collectManifests(): array
    {
        $out = [];

        foreach ($this->pluginDirectories as $dir) {
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }

            foreach (glob(rtrim($dir, '/') . '/*/plugin.json') ?: [] as $manifestFile) {
                $real = realpath($manifestFile);
                if ($real === false) {
                    continue;
                }
                $contents = @file_get_contents($real);
                if ($contents === false) {
                    continue;
                }
                $out[$real] = ['path' => $real, 'contents' => $contents];
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * True when the on-disk stamp matches the hash computed from the supplied
     * discovered manifests. Returns false when caching is disabled.
     *
     * @param array<string, array{path: string, contents: string}> $discovered
     */
    public function isCurrent(array $discovered): bool
    {
        if ($this->stampPath === null) {
            return false;
        }

        $existing = is_readable($this->stampPath) ? file_get_contents($this->stampPath) : false;
        return is_string($existing) && $existing === $this->computeStampHash($discovered);
    }

    /**
     * Decode the sidecar JSON. Returns the `plugins` array on success, or null
     * when caching is disabled, the file is missing/empty, the JSON is invalid,
     * or the top-level shape doesn't match the expected schema.
     *
     * @return list<array<string, mixed>>|null
     */
    public function read(): ?array
    {
        if ($this->stampPath === null) {
            return null;
        }
        return $this->decodeAndValidateSidecar();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function decodeAndValidateSidecar(): ?array
    {
        $sidecarPath = $this->sidecarPath();
        $raw = is_readable($sidecarPath) ? file_get_contents($sidecarPath) : false;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return $this->extractPluginsList($decoded);
    }

    /**
     * @param mixed $decoded
     *
     * @return list<array<string, mixed>>|null
     */
    private function extractPluginsList(mixed $decoded): ?array
    {
        if (!is_array($decoded) || !isset($decoded['plugins']) || !is_array($decoded['plugins'])) {
            return null;
        }
        return $decoded['plugins'];
    }

    /**
     * Persist the stamp hash and the per-plugin sidecar entries to disk.
     * Best-effort: failures are swallowed because a missing cache only
     * triggers a re-scan on the next boot, never a fatal error.
     *
     * @param array<string, array{path: string, contents: string}> $discovered
     * @param list<array{slug: string, class: ?string, directory: ?string, manifest: array<string, mixed>}> $entries
     */
    public function write(array $discovered, array $entries): void
    {
        if ($this->stampPath === null) {
            return;
        }

        $dir = dirname($this->stampPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        @file_put_contents($this->stampPath, $this->computeStampHash($discovered));

        try {
            $payload = json_encode(
                ['plugins' => $entries],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            // Best-effort: an encoding failure is not fatal, the next boot
            // will simply fall back to a full re-scan.
            return;
        }

        @file_put_contents($this->sidecarPath(), $payload);
    }

    /**
     * Build a deterministic sha256 hash over every discovered manifest.
     * Changes to any manifest (mtime or content) invalidate the stamp.
     *
     * @param array<string, array{path: string, contents: string}> $discovered
     */
    private function computeStampHash(array $discovered): string
    {
        $parts = [];
        foreach ($discovered as $entry) {
            $mtime = @filemtime($entry['path']);
            $parts[] = $entry['path'] . "\t" . ($mtime ?: 0) . "\t" . hash('sha256', $entry['contents']);
        }
        return hash('sha256', implode("\n", $parts));
    }

    private function sidecarPath(): string
    {
        return $this->stampPath . self::SIDECAR_SUFFIX;
    }
}

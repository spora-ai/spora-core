<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Extension\PluginPackageName;
use Spora\Plugins\PluginInterface;
use Spora\Plugins\PluginLoader;

/**
 * Builds the inventory of installed plugins surfaced by GET /api/v1/plugins.
 *
 * Combines three sources of truth:
 *  - PluginInterface metadata (name, version, tools, drivers, recipe paths)
 *  - plugin.json manifest (description, slug)
 *  - schema_versions + Laravel `migrations` tables (applied migration state)
 *
 * Plugins are not sandboxed (see docs/07_plugins.md), so reading their metadata
 * is inherently trusted operator data — the controller gates this behind AuthMiddleware.
 */
final class PluginsService
{
    public function __construct(
        private readonly PluginLoader $pluginLoader,
        private readonly PluginMetadataExtractor $metadataExtractor,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listPlugins(): array
    {
        $plugins         = $this->pluginLoader->getPlugins();
        $directories     = $this->pluginLoader->getPluginDirectories();
        $suggests        = $this->pluginLoader->suggestedPackages();
        $result          = [];

        foreach ($plugins as $slug => $plugin) {
            $result[] = $this->buildPluginResource(
                $slug,
                $plugin,
                $directories[$slug] ?? null,
                $suggests[$slug] ?? [],
            );
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPluginResource(string $slug, PluginInterface $plugin, ?string $directory, array $suggests): array
    {
        $manifest          = $this->pluginLoader->getPluginManifest($slug) ?? [];
        $toolClasses       = $plugin->tools();
        $driverClasses     = $plugin->drivers();
        $recipePaths       = $plugin->recipePaths();
        $schemaVersion     = $plugin->schemaVersion();
        $migrationsPath    = $plugin->migrationsPath();

        return [
            'slug'             => $slug,
            'name'             => $plugin->getName(),
            'package'          => $this->readComposerPackageName($directory),
            'description'      => is_string($manifest['description'] ?? null) ? (string) $manifest['description'] : '',
            'icon'             => $this->resolveIcon($manifest),
            'version'          => $schemaVersion,
            'path'             => $directory,
            'bundledTools'     => $this->metadataExtractor->extract($toolClasses),
            'bundledDrivers'   => $this->buildDriverList($driverClasses),
            'recipePaths'      => array_values($recipePaths),
            'migrations'       => $this->buildMigrationStatus($slug, $schemaVersion, $migrationsPath),
            'suggests'         => $suggests,
        ];
    }

    /** Composer `vendor/name` from `<pluginDir>/composer.json`, or null when the plugin has no composer sidecar. Required by the DELETE / PATCH routes' vendor/name regex. */
    private function readComposerPackageName(?string $pluginDir): ?string
    {
        if ($pluginDir === null || $pluginDir === '') {
            return null;
        }

        $path = rtrim($pluginDir, '/') . '/composer.json';
        return is_file($path) ? $this->parseComposerName($path) : null;
    }

    /** Extracts `name` from a composer.json file path. Returns null on any read / parse / shape failure — like `PluginLoader::readComposerSuggest`, errors are not surfaced. */
    private function parseComposerName(string $path): ?string
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        $name    = is_array($decoded) ? ($decoded['name'] ?? null) : null;
        if (!is_string($name)) {
            return null;
        }

        $trimmed = trim($name);
        // Validate against the same shape the DELETE/PATCH routes require
        // so the inventory never exposes a `package` the server would reject.
        return PluginPackageName::isValid($trimmed) ? $trimmed : null;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function resolveIcon(array $manifest): string
    {
        $icon = $manifest['icon'] ?? null;
        if (!is_string($icon)) {
            return 'puzzle';
        }
        $icon = trim($icon);
        return $icon !== '' ? $icon : 'puzzle';
    }

    /**
     * @param array<string, class-string> $driverClasses
     *
     * @return list<array{provider: string, class: string}>
     */
    private function buildDriverList(array $driverClasses): array
    {
        $out = [];
        foreach ($driverClasses as $provider => $class) {
            $out[] = [
                'provider' => (string) $provider,
                'class'    => (string) $class,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMigrationStatus(string $slug, int $schemaVersion, ?string $migrationsPath): array
    {
        $filesOnDisk = $this->countMigrationFiles($migrationsPath, $slug);
        $applied     = $this->countAppliedMigrations($slug);
        $lastApplied = $this->getLastAppliedAt($slug);
        $status      = $this->deriveStatus($migrationsPath, $filesOnDisk, $applied);

        return [
            'declared'       => $schemaVersion,
            'applied'        => $applied,
            'filesOnDisk'    => $filesOnDisk,
            'pending'        => max(0, $filesOnDisk - $applied),
            'lastAppliedAt'  => $lastApplied,
            'status'         => $status,
        ];
    }

    private function countMigrationFiles(?string $migrationsPath, string $slug): int
    {
        if ($migrationsPath === null || $migrationsPath === '' || !is_dir($migrationsPath)) {
            return 0;
        }

        $matches = glob(rtrim($migrationsPath, '/') . '/' . $slug . '_*.php');
        return $matches === false ? 0 : count($matches);
    }

    private function countAppliedMigrations(string $slug): int
    {
        // Slug-prefix match — use SUBSTR for a literal prefix check that works the
        // same in MySQL, PostgreSQL, and SQLite (LIKE's `_` metachar + `_` escape
        // is driver-dependent without an explicit ESCAPE clause).
        $prefix = $slug . '_';
        return (int) Capsule::table('migrations')
            ->whereRaw('SUBSTR(migration, 1, ?) = ?', [strlen($prefix), $prefix])
            ->count();
    }

    private function getLastAppliedAt(string $slug): ?string
    {
        $row = Capsule::table('schema_versions')
            ->where('component', $slug)
            ->first();

        if ($row === null) {
            return null;
        }

        $updatedAt = $row->updated_at ?? null;
        return is_string($updatedAt) && $updatedAt !== '' ? $updatedAt : null;
    }

    private function deriveStatus(?string $migrationsPath, int $filesOnDisk, int $applied): string
    {
        if ($migrationsPath === null || $filesOnDisk === 0) {
            return 'no_migrations';
        }

        return $applied >= $filesOnDisk ? 'up_to_date' : 'pending_migrations';
    }
}

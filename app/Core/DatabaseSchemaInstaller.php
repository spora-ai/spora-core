<?php

declare(strict_types=1);

namespace Spora\Core;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Spora\Plugins\PluginLoader;

/**
 * Versioned, component-aware schema installer built on Illuminate\Migrations\Migrator.
 *
 * Hot-path performance:
 *   When $stampPath is set, install() computes a composite version hash from all
 *   component versions and compares it against a local file. If the hash matches,
 *   the method returns immediately (0 database queries). The stamp is updated after
 *   every successful migration run.
 *
 * Each component (core + every plugin that declares migrations) gets one row in the
 * `schema_versions` table. On a cache miss:
 *   1. Read stored version per component (0 if missing).
 *   2. If stored version >= code version → skip.
 *   3. Otherwise run migrations via the Laravel Migrator and upsert schema_versions.
 *
 * Migration filename contract (plugins):
 *   Every migration file in a plugin's migrations directory MUST start with
 *   the plugin's slug followed by an underscore (e.g. `my-plugin_001_create_foo.php`).
 *   This prevents collisions in the shared Laravel `migrations` tracking table.
 */
final class DatabaseSchemaInstaller
{
    private const CORE_MIGRATIONS_PATH = BASE_PATH . '/database/migrations';

    /**
     * @param  ?PluginLoader  $pluginLoader  Null during early boot or in tests without plugins.
     * @param  ?string        $stampPath     Path to the filesystem stamp file. Null disables caching
     *                                       (used for :memory: SQLite in tests).
     */
    public function __construct(
        private readonly ?PluginLoader $pluginLoader = null,
        private readonly ?string       $stampPath    = null,
    ) {}

    public function install(): void
    {
        // O(1) hot path — 0 database queries when the stamp matches.
        $hash = $this->computeStampHash();

        if ($this->stampPath !== null && $this->isStampCurrent($hash)) {
            return;
        }

        $this->ensureInfrastructureTables();

        $migrator = $this->buildMigrator();

        $this->runComponent($migrator, 'core', $this->getCoreMigrationVersion(), self::CORE_MIGRATIONS_PATH);

        if ($this->pluginLoader !== null) {
            foreach ($this->pluginLoader->pluginMigrationPaths() as $slug => $entry) {
                $this->validateMigrationFilenames($slug, $entry['path']);
                $this->runComponent($migrator, $slug, $entry['version'], $entry['path']);
            }
        }

        // Write the stamp only after all migrations succeed, so a partial failure
        // leaves the stamp stale and triggers a full re-check on next boot.
        if ($this->stampPath !== null) {
            file_put_contents($this->stampPath, $hash);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build a deterministic version string combining all component versions.
     * Format: "core_v1|my-plugin_v2|other-plugin_v1" (components sorted by name).
     */
    private function computeStampHash(): string
    {
        $parts = ['core_v' . $this->getCoreMigrationVersion()];

        if ($this->pluginLoader !== null) {
            $pluginParts = [];
            foreach ($this->pluginLoader->pluginMigrationPaths() as $slug => $entry) {
                $pluginParts[] = $slug . '_v' . $entry['version'];
            }
            sort($pluginParts); // stable order regardless of discovery order
            array_push($parts, ...$pluginParts);
        }

        return implode('|', $parts);
    }

    /**
     * Derive the core schema version from the highest-numbered migration file.
     * This eliminates the need to manually bump a constant when adding migrations.
     */
    private function getCoreMigrationVersion(): int
    {
        $files = glob(self::CORE_MIGRATIONS_PATH . '/[0-9]*.php') ?: [];

        $max = 0;
        foreach ($files as $file) {
            $basename = basename($file, '.php');
            if (preg_match('/^(\d+)/', $basename, $m)) {
                $num = (int) $m[1];
                if ($num > $max) {
                    $max = $num;
                }
            }
        }

        return $max;
    }

    private function isStampCurrent(string $hash): bool
    {
        return is_file($this->stampPath)
            && file_get_contents($this->stampPath) === $hash;
    }

    /**
     * Create `schema_versions` and the Laravel `migrations` table if they don't exist yet.
     * These must exist before the Migrator runs — they are never managed by the Migrator itself.
     */
    private function ensureInfrastructureTables(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('schema_versions')) {
            $schema->create('schema_versions', static function (Blueprint $table): void {
                $table->string('component')->primary();
                $table->unsignedInteger('version')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }

        $repo = new DatabaseMigrationRepository(
            Database::getCapsule()->getDatabaseManager(),
            'migrations',
        );

        if (!$repo->repositoryExists()) {
            $repo->createRepository();
        }
    }

    private function runComponent(Migrator $migrator, string $component, int $codeVersion, string $path): void
    {
        $storedVersion = $this->getStoredVersion($component);

        if ($storedVersion >= $codeVersion) {
            return;
        }

        // run() returns the list of migration names that were actually executed.
        $ranMigrations = $migrator->run($path);

        // For the core component, use the actual last ran migration to derive the
        // version — the file-scan codeVersion can be wrong if a migration file was
        // added but never executed (e.g. stamp was manually touched). For plugins,
        // use codeVersion from the manifest (plugin authors control the version).
        if ($component === 'core') {
            // run() returns full file paths, so extract the migration name (basename without .php).
            $lastMigrationFile = end($ranMigrations) ?: '';
            $lastMigrationName = $lastMigrationFile !== '' ? str_replace('.php', '', basename($lastMigrationFile)) : '';
            $actualVersion    = 0;

            if ($lastMigrationName !== '' && preg_match('/^(\d+)/', $lastMigrationName, $matches)) {
                $actualVersion = (int) $matches[1];
            }

            $this->upsertVersion($component, $actualVersion);
        } else {
            $this->upsertVersion($component, $codeVersion);
        }
    }

    /**
     * Enforce that every migration file in $path starts with "{$slug}_".
     * Throws RuntimeException on the first violation so plugin authors get a clear error.
     */
    private function validateMigrationFilenames(string $slug, string $path): void
    {
        $prefix = $slug . '_';

        foreach (glob($path . '/*.php') ?: [] as $file) {
            $basename = basename($file, '.php');

            if (!str_starts_with($basename, $prefix)) {
                throw new RuntimeException(
                    "Plugin migration file '{$basename}.php' must be prefixed with the plugin slug. " .
                    "Expected filename starting with '{$prefix}', e.g. '{$prefix}{$basename}.php'.",
                );
            }
        }
    }

    private function getStoredVersion(string $component): int
    {
        $row = Capsule::table('schema_versions')
            ->where('component', $component)
            ->first();

        return $row ? (int) $row->version : 0;
    }

    private function upsertVersion(string $component, int $version): void
    {
        $exists = Capsule::table('schema_versions')
            ->where('component', $component)
            ->exists();

        if ($exists) {
            Capsule::table('schema_versions')
                ->where('component', $component)
                ->update(['version' => $version, 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            Capsule::table('schema_versions')->insert([
                'component'  => $component,
                'version'    => $version,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function buildMigrator(): Migrator
    {
        $manager    = Database::getCapsule()->getDatabaseManager();
        $repository = new DatabaseMigrationRepository($manager, 'migrations');

        return new Migrator($repository, $manager, new Filesystem());
    }
}

<?php

declare(strict_types=1);

namespace Spora\Core;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Spora\Core\Exceptions\SchemaInstallFailedException;
use Spora\Extensions\AppLoader;
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
    private readonly string $coreMigrationsPath;

    /**
     * @param  ?PluginLoader  $pluginLoader       Null during early boot or in tests without plugins.
     * @param  ?string        $stampPath          Stamp file path. Null disables caching (e.g. :memory: SQLite in tests).
     * @param  ?string        $coreMigrationsPath Override the core migrations path. Null uses the
     *                                           project-local → framework-vendor fallback chain.
     *                                           Mainly for tests.
     * @param  ?AppLoader     $appLoader          Project App extension. When non-null and the App declares
     *                                           migrations, those migrations are installed under the "app"
     *                                           component name.
     */
    public function __construct(
        private readonly ?PluginLoader $pluginLoader      = null,
        private readonly ?string       $stampPath         = null,
        ?string                        $coreMigrationsPath = null,
        private readonly ?Paths        $paths             = null,
        private readonly ?AppLoader    $appLoader         = null,
    ) {
        $this->coreMigrationsPath = $coreMigrationsPath ?? $this->resolveCoreMigrationsPath();
    }

    public function install(): void
    {
        // O(1) hot path — 0 database queries when the stamp matches.
        $hash = $this->computeStampHash();

        if ($this->stampPath !== null && $this->isStampCurrent($hash)) {
            return;
        }

        $this->ensureInfrastructureTables();

        $migrator = $this->buildMigrator();

        $this->runComponent($migrator, 'core', $this->getCoreMigrationVersion(), $this->coreMigrationsPath);

        if ($this->pluginLoader !== null) {
            foreach ($this->pluginLoader->pluginMigrationPaths() as $slug => $entry) {
                $this->validateMigrationFilenames($slug, $entry['path']);
                $this->runComponent($migrator, $slug, $entry['version'], $entry['path']);
            }
        }

        if ($this->appLoader !== null) {
            $app = $this->appLoader->getApp();
            if ($app !== null && $app->schemaVersion() > 0 && $app->migrationsPath() !== null) {
                $this->validateMigrationFilenames('app', $app->migrationsPath());
                $this->runComponent($migrator, 'app', $app->schemaVersion(), $app->migrationsPath());
            }
        }

        // Write the stamp only after all migrations succeed, so a partial failure
        // leaves the stamp stale and triggers a full re-check on next boot.
        if ($this->stampPath !== null) {
            file_put_contents($this->stampPath, $hash);
        }
    }

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

        if ($this->appLoader !== null) {
            $app = $this->appLoader->getApp();
            if ($app !== null && $app->schemaVersion() > 0 && $app->migrationsPath() !== null) {
                $parts[] = 'app_v' . $app->schemaVersion();
            }
        }

        return implode('|', $parts);
    }

    /**
     * Derive the core schema version from the highest-numbered migration file.
     * This eliminates the need to manually bump a constant when adding migrations.
     */
    private function getCoreMigrationVersion(): int
    {
        $files = glob($this->coreMigrationsPath . '/[0-9]*.php') ?: [];

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

    /**
     * Resolve the on-disk path to the framework's core migrations.
     *
     * Fallback chain:
     *   1. Project-local override at <BASE_PATH>/database/migrations/
     *   2. Framework-bundled at <BASE_PATH>/vendor/spora-ai/spora-core/database/migrations/
     *
     * Throws rather than silently no-op'ing — a silent skip would leave the DB empty
     * and be masked by the O(1) stamp hot path.
     */
    private function resolveCoreMigrationsPath(): string
    {
        $projectLocal = $this->paths?->database('migrations') ?? BASE_PATH . '/database/migrations';
        if (is_dir($projectLocal) && $this->hasVersionedMigrations($projectLocal)) {
            return $projectLocal;
        }

        $framework = $this->paths?->framework('database/migrations') ?? BASE_PATH . '/vendor/spora-ai/spora-core/database/migrations';
        if (is_dir($framework) && $this->hasVersionedMigrations($framework)) {
            return $framework;
        }

        throw new SchemaInstallFailedException(
            'No core migrations found. Expected either ' . $projectLocal
            . ' (project-local override, must contain at least one versioned *.php migration)'
            . ' or ' . $framework
            . ' (framework-bundled, shipped with the spora-ai/spora-core Composer package).'
            . ' Did `composer install` run successfully?',
        );
    }

    /**
     * True when $path contains at least one migration matching the leading-digits
     * versioning contract (`[0-9]*.php`). Guards against selecting an empty dir
     * which would yield version 0 and silent skip via the stamp hot path.
     */
    private function hasVersionedMigrations(string $path): bool
    {
        return count(glob($path . '/[0-9]*.php') ?: []) > 0;
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
     * Throws SchemaInstallFailedException on the first violation so plugin authors get a clear error.
     */
    private function validateMigrationFilenames(string $slug, string $path): void
    {
        $prefix = $slug . '_';

        foreach (glob($path . '/*.php') ?: [] as $file) {
            $basename = basename($file, '.php');

            if (!str_starts_with($basename, $prefix)) {
                throw new SchemaInstallFailedException(
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

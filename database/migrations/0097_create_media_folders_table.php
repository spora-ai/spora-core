<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Folders in the Media Archive, and the two asset columns that point at them.
 *
 * `media_folders` is a principal-scoped tree:
 *
 *   - `principal_id` is the tenant boundary and is `ON DELETE RESTRICT`,
 *     the same shape `media_assets.principal_id` uses.
 *   - `parent_id` is `ON DELETE CASCADE`, so deleting a folder takes its
 *     subfolders with it.
 *   - `path` is the folder's path from the tenant root (`/Corporate`,
 *     `/Corporate/2026`). The UNIQUE `(principal_id, path)` is what makes
 *     sibling names unique: two siblings with the same name produce the
 *     same path and collide. `name` alone is not unique — the same name
 *     may exist under two different parents.
 *
 * `media_assets` gains:
 *
 *   - `folder_id` — `ON DELETE SET NULL`, so a deleted folder leaves its
 *     assets in the root rather than deleting them.
 *   - `kind` — an advisory mark (`asset` / `template` / `example`). Nothing
 *     in the read or write path refuses an asset because of its `kind`;
 *     it is a filter hint for the operator UI.
 *
 * The UNIQUE `(folder_id, filename)` index makes filenames unique within a
 * folder. Assets with no folder are not constrained by it: every row that
 * predates this migration has a `NULL` folder, and a SQL unique index does
 * not compare `NULL`s.
 *
 * Engine notes:
 *
 *   - The `media_assets.folder_id` foreign key is added on MySQL/MariaDB
 *     only. Laravel's SQLite grammar compiles an `ALTER TABLE … ADD
 *     CONSTRAINT` foreign key to nothing — SQLite cannot add a foreign key
 *     to an existing table without a full table rebuild. The service
 *     therefore moves the assets to the root itself when it deletes a
 *     folder, so the behaviour is the same on every engine.
 *   - The unique index is created after the columns, so a re-run over a
 *     partially-applied schema cannot fail on a missing column.
 */
return new class extends Migration
{
    use Spora\Core\Database\MigrationHelpers;

    public function up(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();

        if (!$schema->hasTable('media_folders')) {
            $schema->create('media_folders', static function (Blueprint $t): void {
                // `uuid()` not `char(36)` so the foreign keys match
                // `media_assets.id` natively on MariaDB 10.7+ (errno 150
                // otherwise) — same reasoning as migration 0076.
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('principal_id');
                $t->uuid('parent_id')->nullable();
                $t->string('name', 255);
                $t->string('path', 512);
                $t->timestamps();

                $t->unique(['principal_id', 'path'], 'media_folders_principal_path_unique');
                $t->index('parent_id', 'media_folders_parent_id_idx');
                $t->foreign('principal_id', 'fk_media_folders_principal_id')
                    ->references('id')->on('principals')
                    ->restrictOnDelete();
                $t->foreign('parent_id', 'fk_media_folders_parent_id')
                    ->references('id')->on('media_folders')
                    ->cascadeOnDelete();
            });
        }

        if (!$schema->hasColumn('media_assets', 'folder_id')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->uuid('folder_id')->nullable();
            });
        }

        if (!$schema->hasColumn('media_assets', 'kind')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->string('kind', 16)->default('asset');
            });
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            if (!$this->foreignKeyExists('media_assets', 'fk_media_assets_folder_id')) {
                $schema->table('media_assets', static function (Blueprint $t): void {
                    $t->foreign('folder_id', 'fk_media_assets_folder_id')
                        ->references('id')->on('media_folders')
                        ->nullOnDelete();
                });
            }
        }

        if (!$this->indexExists('media_assets', 'media_assets_folder_filename_unique')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->unique(['folder_id', 'filename'], 'media_assets_folder_filename_unique');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            if ($this->foreignKeyExists('media_assets', 'fk_media_assets_folder_id')) {
                $schema->table('media_assets', static function (Blueprint $t): void {
                    $t->dropForeign('fk_media_assets_folder_id');
                });
            }
        }

        if ($this->indexExists('media_assets', 'media_assets_folder_filename_unique')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->dropIndex('media_assets_folder_filename_unique');
            });
        }

        if ($schema->hasColumn('media_assets', 'kind')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->dropColumn('kind');
            });
        }

        if ($schema->hasColumn('media_assets', 'folder_id')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->dropColumn('folder_id');
            });
        }

        $schema->dropIfExists('media_folders');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Upgrade `media_assets.payload` from BLOB (64 KiB) to MEDIUMBLOB (16 MiB)
 * on MySQL/MariaDB.
 *
 * 0052 added the column as `binary()` — MySQL's default BLOB is 64 KiB,
 * which truncates any `data_url`-mode image or audio above that and
 * surfaces as SQLSTATE 22001 to the operator. The `data_url` path is
 * the only one that writes raw bytes to the `payload` BLOB column.
 *
 * SQLite has no intrinsic BLOB cap; the migration is a no-op there.
 *
 * Forward-only: `down()` is intentionally empty — a downgrade would
 * re-introduce the truncation bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('media_assets') || !$schema->hasColumn('media_assets', 'payload')) {
            return;
        }

        $driver = Capsule::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        // Laravel's Schema builder has no MEDIUMBLOB type; the column is
        // altered via raw DDL. INSTANT/INPLACE algorithms keep the
        // operation O(1) in table size on MySQL 8.0+ / MariaDB 10.4+.
        Capsule::connection()->statement('ALTER TABLE media_assets MODIFY payload MEDIUMBLOB NULL');
    }

    public function down(): void
    {
        // No-op: see the class docblock.
    }
};

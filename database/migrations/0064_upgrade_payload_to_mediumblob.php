<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

/**
 * Widen `media_assets.payload` from BLOB (64 KiB) to MEDIUMBLOB (16 MiB).
 *
 * 0052 added the column as `binary()` — MySQL's default BLOB is 64 KiB
 * and truncates any `data_url`-mode asset above that with SQLSTATE
 * 22001. SQLite has no intrinsic BLOB cap (no-op there). Forward-only:
 * a downgrade would re-introduce the truncation bug.
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

        // Schema builder has no MEDIUMBLOB type; INSTANT/INPLACE keep
        // the ALTER O(1) in table size on MySQL 8.0+ / MariaDB 10.4+.
        Capsule::connection()->statement('ALTER TABLE media_assets MODIFY payload MEDIUMBLOB NULL');
    }

    public function down(): void
    {
    }
};

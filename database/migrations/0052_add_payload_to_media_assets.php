<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('media_assets', function (Blueprint $table) {
            // Real BLOB — raw binary bytes. The previous "data:" inline-URL
            // strategy was a string-shaped workaround for a column that
            // didn't exist; this is the proper architecture. SQLite has no
            // intrinsic BLOB cap. MySQL/MariaDB's default BLOB is 64 KiB,
            // so AutoAssetStore must keep payloads at-or-under that for the
            // DB path; payloads above the threshold fall back to
            // LocalAssetStore (filesystem).
            $table->binary('payload')->nullable();

            // Opaque token used in the public URL. Distinct from the row
            // UUID so the URL never leaks the internal primary key.
            $table->string('asset_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('media_assets', function (Blueprint $table) {
            $table->dropUnique(['asset_token']);
            $table->dropColumn(['payload', 'asset_token']);
        });
    }
};

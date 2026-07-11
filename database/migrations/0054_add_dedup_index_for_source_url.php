<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotency dedup switched from `(tool_call_id, asset_url)` to
        // `(tool_call_id, source_url)` after the asset_url column was
        // rewritten to the opaque `/api/v1/assets/<uuid>` form on every
        // persist ({@see MediaArchiveService::insertNew()}). The unique
        // index on `(tool_call_id, asset_url)` is kept in place — fresh
        // writes still hit it once `asset_url` is rewritten, and it
        // protects against accidental re-use of the (rare) collision
        // where two UUIDs resolve to the same opaque URL. The new
        // `(tool_call_id, source_url)` index is what
        // `MediaArchiveService::findExisting()` actually queries against
        // for re-ingest dedup, and it allows the unique index to track
        // the upstream CDN URL operators supply instead of the internal
        // routing id.
        //
        // We don't use the schema builder's `->unique()` here because
        // SQLite (used in tests + dev) treats nullable-participant unique
        // indexes differently than MySQL: an explicit `CREATE INDEX`
        // with the nullable column always included keeps the lookup
        // working for rows where `source_url` is null (bytes / hex /
        // base64 inputs have no source URL by definition).
        Capsule::schema()->table('media_assets', function (Blueprint $table) {
            $table->index(['tool_call_id', 'source_url'], 'media_assets_dedup_source_url_idx');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('media_assets', function (Blueprint $table) {
            $table->dropIndex('media_assets_dedup_source_url_idx');
        });
    }
};

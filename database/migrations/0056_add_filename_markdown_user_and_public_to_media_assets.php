<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add upload, conversion, ownership, and public-sharing fields to `media_assets`.
 *
 * This migration widens the table from a tool-ingest-only sink into a
 * full media archive: user uploads land here, the converter pipeline
 * writes the extracted markdown, the row carries ownership for
 * per-user filtering, and a user-controlled public access token
 * enables the `GET /api/v1/public/media/<id>?token=<token>` endpoint.
 *
 * Columns:
 *   - `filename`            User-visible filename; falls back to UUID on download
 *                           when null. Optional for both uploads and tool output.
 *   - `markdown_content`     Converter pipeline output. NULL when no converter
 *                           handled the file (e.g. images) or when conversion
 *                           failed — failure is non-fatal, see MediaArchiveService.
 *   - `user_id`              Owner. NULL for tool-generated rows from before this
 *                           column existed; the migration is backfill-safe.
 *   - `upload_source`        Distinguishes user uploads (`'upload'`) from
 *                           tool-generated rows (`'tool'`) so the Media Archive
 *                           can filter and so the upload pipeline knows which
 *                           permission path to take.
 *   - `public_access_token`  When set, the asset is publicly readable via the
 *                           no-auth `/api/v1/public/media/{id}?token=<this>`
 *                           route. Separate from `asset_token` (internal
 *                           storage routing) and the user-controlled
 *                           `public_access_token` is independent — operators
 *                           can rotate or clear it without touching the on-disk
 *                           file path.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('media_assets')) {
            return;
        }

        $schema->table('media_assets', static function (Blueprint $table) use ($schema): void {
            if (!$schema->hasColumn('media_assets', 'filename')) {
                $table->string('filename', 255)->nullable()->after('prompt');
            }
            if (!$schema->hasColumn('media_assets', 'markdown_content')) {
                // MySQL `longText`, SQLite `text` — the Capsule column type maps
                // automatically and we don't need a driver-specific switch.
                $table->longText('markdown_content')->nullable()->after('filename');
            }
            if (!$schema->hasColumn('media_assets', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('tool_call_id');
                $table->index(['user_id', 'created_at'], 'media_assets_user_id_created_at_idx');
            }
            if (!$schema->hasColumn('media_assets', 'upload_source')) {
                $table->string('upload_source', 16)->nullable()->after('user_id');
                $table->index(['upload_source', 'created_at'], 'media_assets_upload_source_created_at_idx');
            }
            if (!$schema->hasColumn('media_assets', 'public_access_token')) {
                $table->string('public_access_token', 64)->nullable()->after('asset_token');
                $table->unique('public_access_token', 'media_assets_public_access_token_unique');
            }
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('media_assets')) {
            return;
        }

        $schema->table('media_assets', static function (Blueprint $table): void {
            if ($schema->hasColumn('media_assets', 'public_access_token')) {
                $table->dropUnique('media_assets_public_access_token_unique');
                $table->dropColumn('public_access_token');
            }
            if ($schema->hasColumn('media_assets', 'upload_source')) {
                $table->dropIndex('media_assets_upload_source_created_at_idx');
                $table->dropColumn('upload_source');
            }
            if ($schema->hasColumn('media_assets', 'user_id')) {
                $table->dropIndex('media_assets_user_id_created_at_idx');
                $table->dropColumn('user_id');
            }
            if ($schema->hasColumn('media_assets', 'markdown_content')) {
                $table->dropColumn('markdown_content');
            }
            if ($schema->hasColumn('media_assets', 'filename')) {
                $table->dropColumn('filename');
            }
        });
    }
};

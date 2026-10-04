<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Drop `media_assets.markdown_content` — extraction moves to `md` derivatives.
 *
 * The column and the derivative pipeline were two parallel contracts for
 * one fact. `create_media` was the clearest proof: it ingested the
 * authored text as the original row, then `PlainTextPassthroughConverter`
 * copied the same bytes into `markdown_content`, storing every document
 * twice. The replacement is a derivative row joined through
 * `media_derivatives`, which carries producer attribution
 * (`producer_plugin` / `producer_operation`) that the column never had
 * and reuses the existing HTTP endpoints, "Convert to" dropdown and
 * OpenAPI surface.
 *
 * Ordering: must run after 0080, which places `transcript` with
 * `->after('markdown_content')`. Dropping the column first would leave
 * 0080's `after()` pointing at a column that no longer exists.
 *
 * No data migration, by operator decision. The dropped values are
 * recoverable at the cost of one `create_derivative(format: "md")` per
 * row (or re-attaching the file to a prompt), whereas a backfill would
 * run the PDF parser over every historical document on the first deploy
 * after the upgrade — unbounded time and CPU on an operator's live
 * archive, with a failure mode (a corrupt or scanned PDF) that has no
 * partial-success story. The trade-off is accepted rather than hidden:
 * see the PR body.
 *
 * Rollback is safe end to end. `down()` re-adds the column as nullable
 * (it is null for every row, which is exactly the pre-converter state
 * for images and unsupported types), and 0080's own `down()` only drops
 * `transcript` / `transcript_language` — it never re-adds
 * `markdown_content`, so a full rollback does not fight this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('media_assets')) {
            return;
        }
        if (!$schema->hasColumn('media_assets', 'markdown_content')) {
            return;
        }

        $schema->table('media_assets', static function (Blueprint $table): void {
            $table->dropColumn('markdown_content');
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('media_assets')) {
            return;
        }
        if ($schema->hasColumn('media_assets', 'markdown_content')) {
            return;
        }

        $schema->table('media_assets', static function (Blueprint $table): void {
            // MySQL `longText`, SQLite `text` — the Capsule column type maps
            // automatically. Mirrors 0056, which added the column in the
            // same shape.
            $table->longText('markdown_content')->nullable();
        });
    }
};

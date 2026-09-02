<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Generic media derivatives join table.
 *
 * Each row links a parent `media_assets.id` to a derivative
 * `media_assets.id` produced by some
 * {@see \Spora\Services\MediaArchive\MediaDerivativeProducerInterface}
 * implementation. The derivative itself is a full `media_assets` row
 * (with its own `principal_id`, `mime_type`, `payload`, etc.) — the
 * join table just records the parent → child relationship and the
 * producer's attribution.
 *
 * Natural-key uniqueness on
 * `(parent_id, format, producer_plugin, producer_operation)` makes
 * re-rendering the same source with the same producer idempotent —
 * the service refreshes the existing derivative row's bytes instead
 * of stacking duplicates.
 *
 * `principal_id` lives on the derivative's own `media_assets` row, not
 * here: each derivative inherits via its own column and the LIST
 * endpoints can filter the media_assets side on principal without an
 * extra join.
 *
 * Cascade deletes keep the link row in sync with both sides — deleting
 * the parent or the derivative cleans up the join automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasTable('media_derivatives')) {
            return;
        }
        $schema->create('media_derivatives', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('parent_id', 36);
            $t->string('derivative_id', 36);
            $t->string('format', 16);
            $t->string('producer_plugin', 64)->nullable();
            $t->string('producer_operation', 64)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(
                ['parent_id', 'format', 'producer_plugin', 'producer_operation'],
                'media_derivatives_natural_key',
            );
            $t->index('parent_id',     'media_derivatives_parent_id_idx');
            $t->index('derivative_id', 'media_derivatives_derivative_id_idx');
            $t->foreign('parent_id',     'fk_media_derivatives_parent')
                ->references('id')->on('media_assets')
                ->cascadeOnDelete();
            $t->foreign('derivative_id', 'fk_media_derivatives_derivative')
                ->references('id')->on('media_assets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        $schema->dropIfExists('media_derivatives');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add the `group_pictures` table (1:1 with `groups`).
 *
 * Mirrors {@see 0062_create_agent_pictures_table} — each group owns
 * either an archetype avatar (icon + variant + palette) or an uploaded
 * image (FK to media_assets), and the XOR invariant is enforced in
 * {@see \Spora\Services\ProfilePictures\GroupPictureService}, not at
 * the DB level. The schema rationale comments on `agent_pictures`
 * (column shape, FK on media_asset_id with NULL on delete, no DEFAULT
 * row) carry over unchanged.
 *
 * Forward-only: `down()` is a no-op — dropping the table would
 * destroy operator-chosen pictures.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('group_pictures')) {
            return;
        }

        $schema->create('group_pictures', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('group_id');
            $table->string('archetype', 32)->nullable();
            $table->string('variant_key', 8)->nullable();
            $table->string('palette_key', 32)->nullable();
            $table->string('media_asset_id', 36)->nullable();
            $table->timestamps();

            $table->unique('group_id', 'uq_group_pictures_group_id');
            $table->foreign('group_id', 'fk_group_pictures_group_id')
                ->references('id')
                ->on('groups')
                ->cascadeOnDelete();
            $table->foreign('media_asset_id', 'fk_group_pictures_media_asset_id')
                ->references('id')
                ->on('media_assets')
                ->nullOnDelete();
        });
    }

    /**
     * Intentional no-op. See the class docblock: dropping the table would
     * destroy operator-chosen pictures.
     */
    public function down(): void
    {
        // Forward-only — see class docblock.
    }
};

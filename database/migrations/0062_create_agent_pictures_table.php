<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add the `agent_pictures` table (1:1 with `agents`).
 *
 * The agent_pictures table carries the operator-chosen profile picture for
 * each agent — either an archetype avatar (icon + variant + palette) or an
 * uploaded image (FK to media_assets). It lives in its own table so the
 * agents row stays narrow (the agents row is already at 13+ columns), and
 * the upload payload (always a separate media_assets row) never touches
 * the agents table.
 *
 * Schema rationale:
 *   - `archetype` is the operator's chosen archetype (one of 8). Nullable
 *     because the picture may be an uploaded image instead.
 *   - `variant_key` is the operator's chosen variant within the archetype
 *     (v0..v2). Nullable to mean "auto-derive from fnv1a(agent_id) % 3".
 *   - `palette_key` is the operator's chosen FG+BG pair (one of 10). Server
 *     resolves the concrete hex codes from this key on every read.
 *   - `media_asset_id` is the FK to media_assets.id for uploaded images.
 *     Nullable because the picture may be an archetype instead.
 *   - XOR invariant (`archetype` set XOR `media_asset_id` set) is enforced
 *     in {@see \Spora\Services\AgentPictures\AgentPictureService}, not in
 *     the database, so migrations stay simple and the rule is co-located
 *     with the rest of the picture logic.
 *
 * Forward-only: `down()` is a no-op — dropping the table would destroy
 * operator-chosen pictures. See 0056_add_agent_pin_archive and
 * 0059_add_notes_to_agents for the identical rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('agent_pictures')) {
            return;
        }

        $schema->create('agent_pictures', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_id');
            $table->string('archetype', 32)->nullable();
            $table->string('variant_key', 8)->nullable();
            $table->string('palette_key', 32)->nullable();
            $table->string('media_asset_id', 36)->nullable();
            $table->timestamps();

            $table->unique('agent_id', 'uq_agent_pictures_agent_id');
            $table->foreign('agent_id', 'fk_agent_pictures_agent_id')
                ->references('id')
                ->on('agents')
                ->cascadeOnDelete();
            $table->foreign('media_asset_id', 'fk_agent_pictures_media_asset_id')
                ->references('id')
                ->on('media_assets')
                ->nullOnDelete();
        });
    }

    /**
     * Intentional no-op. See the class docblock: dropping the table would
     * destroy operator-chosen pictures. The method is kept (rather than
     * removed) so the migrator's reflection-based rollback contract is
     * unchanged.
     */
    public function down(): void
    {
        // Forward-only — see class docblock.
    }
};

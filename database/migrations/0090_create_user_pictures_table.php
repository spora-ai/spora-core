<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add the `user_pictures` table (1:1 with `users`).
 *
 * Carries the profile picture each user uploaded from the Account
 * settings page. Differs from `agent_pictures` / `group_pictures` in
 * two ways:
 *
 *   1. **No archetype branch.** Agent and group pictures carry an
 *      archetype / variant_key / palette_key so the avatar renders as
 *      a deterministic SVG when no upload is set. Users never see an
 *      archetype picker — when no row exists the frontend falls back
 *      to the existing initials circle. Columns `archetype`,
 *      `variant_key`, and `palette_key` are therefore intentionally
 *      absent.
 *
 *   2. **No `media_assets` FK.** Agent and group pictures reuse the
 *      Media Archive (`media_assets.user_id` + ownership union), so
 *      principal-scoped sharing is enforced at the byte URL. User
 *      pictures are visible to *every* logged-in user (group members,
 *      admins, anyone in `/account` + the navbar identity), which is
 *      the opposite of principal-scoped. Routing through MediaArchive
 *      would muddy the visibility semantics of that table. The bytes
 *      live at `<storage>/user-pictures/<user_id>.<ext>` and are served
 *      by {@see \Spora\Http\UserPictureAssetController}, which enforces
 *      a single auth check (any logged-in user) without the principal
 *      union that `AssetController::canAccessAsset()` applies.
 *
 * Schema rationale:
 *   - `media_path` is relative to `<storage>/` (e.g. `user-pictures/12.png`).
 *     A single file per user — atomic rename on replace — keeps the
 *     write path simple and lets the controller serve the file
 *     directly via `readfile()` without a MediaAsset row in between.
 *   - `mime` / `size_bytes` are denormalised from the upload so the
 *     serving controller can set `Content-Type` and `Content-Length`
 *     without re-decoding the bytes.
 *   - UNIQUE on `user_id` enforces the 1:1 invariant at the DB level
 *     so a race between two concurrent first-time uploads is atomic:
 *     `UserPictureService::upload()` uses `updateOrCreate()` keyed on
 *     `user_id`, and a 23000 UNIQUE violation from the loser triggers
 *     a single retry whose lookup now finds the winner's row.
 *   - CASCADE on `users.id` delete keeps the row in sync when a user
 *     is removed by {@see \Spora\Services\UserService::deleteUser()}.
 *
 * Forward-only: `down()` is a no-op — dropping the table would destroy
 * user-uploaded pictures.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('user_pictures')) {
            return;
        }

        $schema->create('user_pictures', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('media_path', 255);
            $table->string('mime', 32);
            $table->unsignedInteger('size_bytes');
            $table->timestamps();

            $table->unique('user_id', 'uq_user_pictures_user_id');
            $table->foreign('user_id', 'fk_user_pictures_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    /**
     * Intentional no-op. See the class docblock: dropping the table
     * would destroy user-uploaded pictures.
     */
    public function down(): void
    {
        // Forward-only — see class docblock.
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One location concept, keyed on the principal.
 *
 * `user_locations` was keyed on `users.id`; the ownership model moved to
 * principals (migration 0067), and a group needs locations of its own, so
 * the table is re-keyed on `principals.id` and the user rows are copied
 * across.
 *
 * The copy is preceded by a defensive backfill: a user without a
 * user-principal would have their locations silently dropped by the
 * `INNER JOIN`, so every user without one gets a principal first. The
 * `principals.user_id` UNIQUE index makes the insert idempotent, and
 * `INSERT OR IGNORE` / `INSERT IGNORE` makes it safe to repeat.
 *
 * `down()` reverses the move: the user-scoped table comes back, the rows
 * that belong to a user-principal are copied into it, and the
 * principal-scoped table is dropped. The group-scoped rows have no
 * user-scoped home and are lost — the same way they were gained.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('principal_locations')) {
            $schema->create('principal_locations', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('principal_id');
                $table->string('name', 100);
                $table->text('address');
                $table->boolean('is_default')->default(false);
                $table->timestamps();

                $table->foreign('principal_id')
                    ->references('id')->on('principals')
                    ->cascadeOnDelete();
                $table->index(['principal_id', 'is_default'], 'idx_principal_locations_principal_default');
            });
        }

        if (!$schema->hasTable('user_locations')) {
            return;
        }

        $this->backfillUserPrincipals();
        $this->copyLocations();

        $schema->drop('user_locations');
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('user_locations')) {
            $schema->create('user_locations', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->string('name', 100);
                $table->text('address');
                $table->boolean('is_default')->default(false);
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['user_id', 'is_default']);
            });
        }

        if ($schema->hasTable('principal_locations') && $schema->hasTable('principals')) {
            Capsule::statement(
                'INSERT INTO user_locations (user_id, name, address, is_default, created_at, updated_at) '
                . 'SELECT p.user_id, pl.name, pl.address, pl.is_default, pl.created_at, pl.updated_at '
                . 'FROM principal_locations pl '
                . "INNER JOIN principals p ON p.id = pl.principal_id AND p.type = 'user'"
            );
        }

        if ($schema->hasTable('principal_locations')) {
            $schema->drop('principal_locations');
        }
    }

    /**
     * Give every user a user-principal, so the copy below has a row to
     * attach their locations to.
     */
    private function backfillUserPrincipals(): void
    {
        $driver = Capsule::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            Capsule::statement(
                'INSERT IGNORE INTO principals (type, user_id, created_at, updated_at) '
                . "SELECT 'user', id, NOW(), NOW() FROM users"
            );
            return;
        }

        Capsule::statement(
            'INSERT OR IGNORE INTO principals (type, user_id, created_at, updated_at) '
            . "SELECT 'user', id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM users"
        );
    }

    private function copyLocations(): void
    {
        Capsule::statement(
            'INSERT INTO principal_locations (principal_id, name, address, is_default, created_at, updated_at) '
            . 'SELECT p.id, ul.name, ul.address, ul.is_default, ul.created_at, ul.updated_at '
            . 'FROM user_locations ul '
            . "INNER JOIN principals p ON p.type = 'user' AND p.user_id = ul.user_id"
        );
    }
};

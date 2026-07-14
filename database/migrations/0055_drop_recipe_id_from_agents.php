<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Drop `recipe_id` from the `agents` table.
 *
 * `recipe_id` was a 100-char nullable string intended to record which
 * Agent Template a given agent was created from. The concept is wrong:
 * Agent Templates are files on disk, not database entities, and they
 * have no canonical ID. The `id` field in a template's JSON is a slug
 * used for matching and source resolution, not a foreign key.
 *
 * Going forward, the import controller still records traceability in
 * the seeder's logs but the column itself is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasTable('agents') && $schema->hasColumn('agents', 'recipe_id')) {
            $schema->table('agents', static function (Blueprint $table): void {
                $table->dropColumn('recipe_id');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasTable('agents') && !$schema->hasColumn('agents', 'recipe_id')) {
            $schema->table('agents', static function (Blueprint $table): void {
                $table->string('recipe_id', 100)->nullable();
            });
        }
    }
};

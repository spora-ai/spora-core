<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add `is_pinned` and `is_archived` flags to the `agents` table.
 *
 * These are user-facing toggles: `is_pinned` keeps an agent at the top of
 * the dashboard list regardless of activity, and `is_archived` hides it
 * from the default view while keeping the row (and its tasks / tool
 * history) intact for later restoration. Both default to false so the
 * change is backward compatible — every existing agent surfaces
 * un-pinned / un-archived.
 *
 * Forward-only: no `down()` rollback — removing the columns would orphan
 * the dashboard ordering data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('agents')) {
            return;
        }

        if (!$schema->hasColumn('agents', 'is_pinned')) {
            $schema->table('agents', static function (Blueprint $table): void {
                $table->boolean('is_pinned')->default(0);
            });
        }
        if (!$schema->hasColumn('agents', 'is_archived')) {
            $schema->table('agents', static function (Blueprint $table): void {
                $table->boolean('is_archived')->default(0);
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('agents')) {
            return;
        }

        if ($schema->hasColumn('agents', 'is_pinned')) {
            $schema->table('agents', static function (Blueprint $table): void {
                $table->dropColumn('is_pinned');
            });
        }
        if ($schema->hasColumn('agents', 'is_archived')) {
            $schema->table('agents', static function (Blueprint $table): void {
                $table->dropColumn('is_archived');
            });
        }
    }
};

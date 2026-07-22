<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add operator-facing `notes` (markdown) to the `agents` table.
 *
 * Operators use this field for runbooks, behaviour hints, and context the
 * agent should remember between sessions. Read/write is exposed both via
 * the operator UI (`PATCH /api/v1/agents/{id}`) and to the agent itself
 * via the new `agent` tool's `read_notes` / `write_notes` operations, so
 * the column must hold markdown (multi-line, possibly large). `mediumText`
 * (~16 MB) gives operators room for power-user notes without bumping the
 * schema again, while staying well within MariaDB / MySQL row-size limits
 * when other agent columns stay small.
 *
 * Forward-only: `down()` is a no-op — removing the column would destroy
 * operator notes. See 0056_add_agent_pin_archive and 0058_add_agent_favorite
 * for the identical rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('agents')) {
            return;
        }

        if (!$schema->hasColumn('agents', 'notes')) {
            $schema->table('agents', static function (Blueprint $table): void {
                $table->mediumText('notes')->nullable();
            });
        }
    }

    /**
     * Intentional no-op. See the class docblock: dropping the column would
     * destroy operator notes. Kept (rather than removed) so the migrator's
     * reflection-based rollback contract is unchanged.
     */
    public function down(): void
    {
        // Forward-only — see class docblock.
    }
};
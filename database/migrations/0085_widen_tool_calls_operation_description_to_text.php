<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

/**
 * Widen `tool_calls.operation_description` from VARCHAR(500) to TEXT.
 *
 * The column was sized to VARCHAR(500) when it landed in migration 0019, but
 * `MediaTool::list_derivatives`'s description docstring runs ~615 chars and
 * the MariaDB engine returns 1406 (Data too long for column) at INSERT time —
 * surfaced in production as a cryptic "System Error: SQLSTATE[22001]…". The
 * cleanest fix is to drop the cap entirely: TEXT has no per-column byte limit
 * for our purposes and is the smallest upgrade that guarantees no future
 * docstring growth re-triggers the same SQLSTATE 22001 family of errors.
 *
 * A companion validator in `app/Agents/ToolCallInsertGuard.php` then
 * protects *every* bounded column on `tool_calls`, not just this one — a
 * future regression on `provider_call_id`, `tool_name`, `tool_class`,
 * `tool_type`, `status`, `operation`, `approval_note`, etc. will surface as
 * a clear `ToolCallFieldOverflowException` instead of a raw 1406.
 *
 * SQLite has no intrinsic TEXT cap (TEXT stores any length on disk), so this
 * migration is a no-op there. Forward-only: a downgrade would re-introduce
 * the truncation bug for any tool whose description exceeds 500 chars.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        $driver = Capsule::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        if (!$schema->hasTable('tool_calls') || !$schema->hasColumn('tool_calls', 'operation_description')) {
            return;
        }

        // Schema builder has no TEXT type for column modifications; raw ALTER
        // keeps the operation O(1) in table size on MySQL 8.0+ and MariaDB
        // 10.4+ via INSTANT/INPLACE.
        Capsule::connection()->statement(
            'ALTER TABLE tool_calls MODIFY operation_description TEXT NULL',
        );
    }

    public function down(): void
    {
        // Forward-only — downgrading would re-introduce the truncation bug.
    }
};
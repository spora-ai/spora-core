<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

/**
 * Widen `tool_calls.operation_description` from VARCHAR(500) to TEXT.
 *
 * Trigger: MediaTool::list_derivatives' description (~615 chars) hit
 * MariaDB 1406 at INSERT. Forward-only — downgrading re-introduces the
 * truncation. {@see \Spora\Services\MaxLengthValidator} plus the
 * per-model save() overrides now defend every bounded column on this
 * table; this migration only needs to drop the cap on
 * `operation_description` itself. SQLite is a no-op.
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

        Capsule::connection()->statement(
            'ALTER TABLE tool_calls MODIFY operation_description TEXT NULL',
        );
    }

    public function down(): void
    {
        // Forward-only — downgrading re-introduces the truncation bug.
    }
};
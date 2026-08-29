<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add lease columns to `tasks` so the reaper can detect orphaned RUNNING rows
 * regardless of whether the driver is the server-mode daemon or a browser
 * SharedWorker. `lease_owner` (e.g. `user:42`, `server:housekeeping`,
 * `server:worker`) identifies the current lease holder; `lease_expires_at`
 * is the wall-clock deadline after which the reaper treats the row as an
 * orphan even if `updated_at` is recent.
 *
 * The composite `(status, lease_expires_at)` index accelerates the reaper's
 * "find RUNNING rows whose lease has expired" scan; it MUST be created
 * non-blocking on MySQL/MariaDB (`ALGORITHM=INPLACE LOCK=NONE`) so the
 * migration is safe to run against a live `tasks` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();

        $schema->table('tasks', static function (Blueprint $t): void {
            $t->string('lease_owner', 100)->nullable()->after('error_message');
            $t->dateTime('lease_expires_at')->nullable()->after('lease_owner');
        });

        if ($driver === 'mysql' || $driver === 'mariadb') {
            Capsule::statement(
                'ALTER TABLE tasks ADD INDEX tasks_status_lease_expires_at_index (status, lease_expires_at), '
                . 'ALGORITHM=INPLACE, LOCK=NONE'
            );
        } else {
            $schema->table('tasks', static function (Blueprint $t): void {
                $t->index(['status', 'lease_expires_at'], 'tasks_status_lease_expires_at_index');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            Capsule::statement(
                'ALTER TABLE tasks DROP INDEX tasks_status_lease_expires_at_index, '
                . 'ALGORITHM=INPLACE, LOCK=NONE'
            );
        } else {
            $schema->table('tasks', static function (Blueprint $t): void {
                $t->dropIndex('tasks_status_lease_expires_at_index');
            });
        }

        $schema->table('tasks', static function (Blueprint $t): void {
            $t->dropColumn('lease_expires_at');
            $t->dropColumn('lease_owner');
        });
    }
};
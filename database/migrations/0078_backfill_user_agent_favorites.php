<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill `user_agent_favorites` from the legacy `agents.is_favorite`
 * column before the column is dropped in migration 0079.
 *
 * Two paths because the storage model is per-user even though the old
 * column was per-agent:
 *
 *   1. User-owned agents → one row per (user, agent).
 *   2. Group-owned agents → one row per (group_member, agent). A user
 *      who belongs to N groups owning the same agent still gets one row
 *      per (user, agent) — the pivot enforces uniqueness.
 *
 * Idempotent via the `INSERT IGNORE` / `INSERT OR IGNORE` dialects and
 * the composite primary key. Re-running the backfill is a no-op.
 *
 * The forward-only `down()` is documented in the migration file header
 * per the spora-core convention (every additive migration since 0056 keeps
 * `down()` as a no-op). Drop the column only if the operator has a
 * pre-0079 backup of `agents.is_favorite`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Capsule::connection()->getDriverName();
        $ignoreClause = ($driver === 'mysql' || $driver === 'mariadb') ? 'IGNORE' : 'OR IGNORE';

        Capsule::connection()->transaction(static function () use ($ignoreClause): void {
            // User-owned agents: one row per (owner_user_id, agent_id).
            Capsule::statement(
                "INSERT {$ignoreClause} INTO user_agent_favorites (user_id, agent_id, created_at) "
                . "SELECT principals.user_id, agents.id, CURRENT_TIMESTAMP "
                . "FROM agents "
                . "INNER JOIN principals ON principals.id = agents.principal_id "
                . "WHERE principals.type = 'user' AND agents.is_favorite = 1"
            );

            // Group-owned agents: one row per group member. Composite PK
            // collapses duplicates when a user is in multiple groups
            // owning the same agent (impossible per the principal XOR
            // invariant, but `INSERT IGNORE` is defensive).
            Capsule::statement(
                "INSERT {$ignoreClause} INTO user_agent_favorites (user_id, agent_id, created_at) "
                . "SELECT group_memberships.user_id, agents.id, CURRENT_TIMESTAMP "
                . "FROM agents "
                . "INNER JOIN principals ON principals.id = agents.principal_id "
                . "INNER JOIN group_memberships ON group_memberships.group_id = principals.group_id "
                . "WHERE principals.type = 'group' AND agents.is_favorite = 1"
            );
        });
    }

    public function down(): void
    {
        // Forward-only: dropping rows would destroy the post-migration
        // state. Operators who need to roll back must restore the column
        // from a pre-0079 backup of the `agents` table.
    }
};

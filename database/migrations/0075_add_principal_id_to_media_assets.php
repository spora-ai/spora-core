<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

/**
 * Add `principal_id` to `media_assets`.
 *
 * Why nullable:
 *
 *   Pre-existing `media_assets` rows can be uploaded by anonymous ingestion
 *   paths (pre-cutover data, batch imports, operator recovery). Promoting
 *   the column to NOT NULL would block those rows from being migrated in,
 *   so we keep it nullable and document the post-migration `IS NULL` state
 *   in `MediaArchiveService::applyPrincipalIdScope()`'s back-compat branch
 *   (the agent-join fallback) so legacy rows still surface under the same
 *   principal until an operator bulk-tags them.
 *
 * Two-pass backfill:
 *
 *   1. `principal_id = agents.principal_id WHERE agent_id IS NOT NULL`
 *      covers every tool-generated asset (a row stamped by an agent always
 *      has the agent's principal in scope).
 *   2. `principal_id = user-principal WHERE user_id IS NOT NULL AND
 *      agent_id IS NULL` covers direct uploads — the user-principal is
 *      materialised on demand via {@see PrincipalService::ensureUserPrincipal()}.
 *
 *   Rows with neither `agent_id` nor `user_id` stay NULL — those surface
 *   only under the un-scoped `ALL` chip in the plugin UI today. Operators
 *   can bulk-tag them later via PATCH.
 *
 * Idempotency:
 *
 *   Every step gates on `hasColumn` / `indexExists` / `foreignKeyExists`,
 *   and the backfill UPDATEs are scoped to `WHERE principal_id IS NULL`,
 *   so re-running on a partially-migrated DB is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();

        if (!$schema->hasColumn('media_assets', 'principal_id')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->unsignedBigInteger('principal_id')->nullable()->after('id');
                $t->index('principal_id', 'idx_media_assets_principal_id');
            });
        } elseif (!$this->indexExists('media_assets', 'idx_media_assets_principal_id')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->index('principal_id', 'idx_media_assets_principal_id');
            });
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            Capsule::statement(
                'UPDATE media_assets ma '
                . 'JOIN agents a ON a.id = ma.agent_id '
                . 'SET ma.principal_id = a.principal_id '
                . 'WHERE ma.principal_id IS NULL AND ma.agent_id IS NOT NULL',
            );
        } else {
            Capsule::statement(
                'UPDATE media_assets '
                . 'SET principal_id = (SELECT principal_id FROM agents WHERE id = media_assets.agent_id) '
                . 'WHERE principal_id IS NULL AND agent_id IS NOT NULL',
            );
        }

        // Direct uploads: materialise the user-principal on demand.
        // The resolver's `ensureUserPrincipal()` is idempotent — repeated
        // calls return the existing row, so a re-run of this migration is
        // safe.
        $resolver = new PrincipalService(new PrincipalResolver());
        $uploadRows = Capsule::table('media_assets')
            ->whereNotNull('user_id')
            ->whereNull('agent_id')
            ->whereNull('principal_id')
            ->select(['id', 'user_id'])
            ->get();
        foreach ($uploadRows as $row) {
            $principalId = $resolver->ensureUserPrincipal((int) $row->user_id)->id;
            Capsule::table('media_assets')
                ->where('id', $row->id)
                ->update(['principal_id' => $principalId]);
        }

        if (!$this->foreignKeyExists('media_assets', 'fk_media_assets_principal_id')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->foreign('principal_id', 'fk_media_assets_principal_id')
                    ->references('id')->on('principals')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();
        // SQLite doesn't support dropping FKs by name through the
        // schema builder — we rebuild the table without the column.
        // The forward migration is what production runs anyway; this
        // is purely for test isolation.
        if ($driver === 'sqlite') {
            if ($schema->hasColumn('media_assets', 'principal_id')) {
                $schema->table('media_assets', static function (Blueprint $t): void {
                    $t->dropIndex('idx_media_assets_principal_id');
                    $t->dropColumn('principal_id');
                });
            }
            return;
        }
        if ($this->foreignKeyExists('media_assets', 'fk_media_assets_principal_id')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->dropForeign('fk_media_assets_principal_id');
            });
        }
        if ($this->indexExists('media_assets', 'idx_media_assets_principal_id')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->dropIndex('idx_media_assets_principal_id');
            });
        }
        if ($schema->hasColumn('media_assets', 'principal_id')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->dropColumn('principal_id');
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = Capsule::selectOne(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS '
                . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? '
                . "AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
                [$table, $constraintName],
            );
            return $row !== null;
        }

        $column = substr($constraintName, strlen("fk_{$table}_"));
        $fks = Capsule::select("PRAGMA foreign_key_list('{$table}')");
        foreach ($fks as $fk) {
            if ($fk->from === $column) {
                return true;
            }
        }
        return false;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = Capsule::selectOne(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
                . 'AND INDEX_NAME = ? LIMIT 1',
                [$table, $indexName],
            );
            return $row !== null;
        }

        $rows = Capsule::select("PRAGMA index_list('{$table}')");
        foreach ($rows as $row) {
            if ($row->name === $indexName) {
                return true;
            }
        }
        return false;
    }
};

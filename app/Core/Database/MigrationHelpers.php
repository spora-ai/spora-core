<?php

declare(strict_types=1);

namespace Spora\Core\Database;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Driver-aware schema-existence helpers used by migrations that need to
 * drop FKs / indexes / columns safely on partial state (a re-run after
 * a previous failure, or an upgrade over an older schema variant).
 *
 * Every method reads from `Capsule::connection()` — the same connection
 * the migration itself uses — so there's no coupling to a specific
 * connection name. The MySQL / MariaDB path queries `information_schema`;
 * the SQLite path uses `PRAGMA foreign_key_list` / `PRAGMA index_list`.
 *
 * Pattern extracted from `database/migrations/0067_introduce_principals_and_groups`
 * and `database/migrations/0073_add_principal_id_and_trigger_user_id_to_tasks`,
 * where the same private methods were duplicated.
 */
trait MigrationHelpers
{
    /** Driver-aware FK existence check used to gate re-runs of this
     *  forward-only migration; the migration runs outside a transaction
     *  (so `PRAGMA foreign_keys = OFF` can take effect during the
     *  `user_id` drop) and a partial failure must be safe to replay. */
    public function foreignKeyExists(string $table, string $constraintName): bool
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

        // SQLite: PRAGMA foreign_key_list returns anonymous FKs (numeric id),
        // and Laravel's SQLite grammar emits FK declarations WITHOUT a
        // CONSTRAINT name in the CREATE TABLE, so a name match against the
        // table's stored DDL would always miss. Match by the `from` column
        // that the constraint name implies — derive it by stripping the
        // `fk_<table>_` prefix.
        $column = substr($constraintName, strlen("fk_{$table}_"));
        $fks = Capsule::select("PRAGMA foreign_key_list('{$table}')");
        foreach ($fks as $fk) {
            if ($fk->from === $column) {
                return true;
            }
        }
        return false;
    }

    /** Driver-aware index existence check — mirrors foreignKeyExists for
     *  the index side of every guarded step. */
    public function indexExists(string $table, string $indexName): bool
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

    /** Driver-aware lookup for the FK that references $column on $table.
     *  Returns the constraint name, or null if none. */
    public function findForeignKeyOn(string $table, string $column): ?string
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = Capsule::selectOne(
                'SELECT kcu.CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE kcu '
                . 'INNER JOIN information_schema.TABLE_CONSTRAINTS tc '
                . 'ON tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA '
                . 'AND tc.TABLE_NAME = kcu.TABLE_NAME '
                . 'AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME '
                . 'WHERE kcu.TABLE_SCHEMA = DATABASE() '
                . 'AND kcu.TABLE_NAME = ? '
                . 'AND kcu.COLUMN_NAME = ? '
                . "AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
                [$table, $column],
            );
            return $row?->CONSTRAINT_NAME;
        }

        // SQLite: PRAGMA exposes the auto-assigned numeric FK id, not the
        // constraint name. Returns null — the only caller (the MySQL/MariaDB
        // user_id drop branch) never executes on SQLite.
        return null;
    }

    /** Driver-aware lookup for the index whose leftmost column is $column.
     *  Returns the index name, or null if none. */
    public function findIndexOn(string $table, string $column): ?string
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = Capsule::selectOne(
                'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() '
                . 'AND TABLE_NAME = ? '
                . 'AND COLUMN_NAME = ? '
                . 'AND SEQ_IN_INDEX = 1 '
                . "AND INDEX_NAME <> 'PRIMARY' LIMIT 1",
                [$table, $column],
            );
            return $row?->INDEX_NAME;
        }

        // SQLite: PRAGMA index_list exposes origin ('c' = user-created,
        // 'pk' = PRIMARY KEY, 'f' = FK auto-index). Skip auto indexes so
        // we never return the FK's anonymous id.
        $rows = Capsule::select("PRAGMA index_list('{$table}')");
        foreach ($rows as $row) {
            if ($row->origin !== 'c') {
                continue;
            }
            $cols = Capsule::select("PRAGMA index_info('{$row->name}')");
            if ($cols !== [] && $cols[0]->name === $column) {
                return $row->name;
            }
        }
        return null;
    }

    /** Returns true if the named table has a PRIMARY KEY (any column).
     *  MySQL/MariaDB only — callers gate by driver. */
    public function hasPrimaryKey(string $table): bool
    {
        $row = Capsule::selectOne(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . "AND INDEX_NAME = 'PRIMARY' LIMIT 1",
            [$table],
        );
        return $row !== null;
    }

    /** Driver-agnostic check for any FK attached to $column on $table.
     *  Unlike `foreignKeyExists`, this does not depend on the FK name
     *  matching `fk_<table>_<column>` — it just probes whether a
     *  constraint exists. On MySQL/MariaDB it goes through
     *  information_schema; on SQLite it scans PRAGMA foreign_key_list
     *  (the only path that exposes FKs in SQLite, since
     *  `information_schema` was added late and Laravel's SQLite grammar
     *  emits FKs with anonymous ids, not names). */
    public function hasForeignKeyOnColumn(string $table, string $column): bool
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            return $this->findForeignKeyOn($table, $column) !== null;
        }

        // SQLite: PRAGMA foreign_key_list exposes FKs as anonymous
        // numeric-id rows, with `from` = the constrained column and
        // `table` = the referenced table. Match by `from`.
        $fks = Capsule::select("PRAGMA foreign_key_list('{$table}')");
        foreach ($fks as $fk) {
            if ($fk->from === $column) {
                return true;
            }
        }
        return false;
    }
}

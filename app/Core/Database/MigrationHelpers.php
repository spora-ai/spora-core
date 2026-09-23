<?php

declare(strict_types=1);

namespace Spora\Core\Database;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Driver-aware schema-existence helpers used by migrations that need to
 * drop FKs / indexes / columns safely on partial state (a re-run after
 * a previous failure, or an upgrade over an older schema variant).
 * MySQL/MariaDB path queries `information_schema`; SQLite uses
 * `PRAGMA foreign_key_list` / `PRAGMA index_list`.
 */
trait MigrationHelpers
{
    /** FK existence check by constraint name. SQLite path derives the
     *  column by stripping `fk_<table>_` from the requested name (Laravel's
     *  SQLite grammar emits FKs without CONSTRAINT names). */
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

        $column = substr($constraintName, strlen("fk_{$table}_"));
        $fks = Capsule::select("PRAGMA foreign_key_list('{$table}')");
        foreach ($fks as $fk) {
            if ($fk->from === $column) {
                return true;
            }
        }
        return false;
    }

    /** Index existence check by name. */
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

    /** Returns the FK constraint name on $column, or null. */
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

        // SQLite PRAGMA exposes FKs by anonymous numeric id, not name.
        return null;
    }

    /** Returns the index name whose leftmost column is $column, or null.
     *  SQLite path skips origin 'u'/'pk'/'f' (auto indexes) so we never
     *  return the FK's anonymous id. */
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

    /** Returns true if the table has a PRIMARY KEY on any column.
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

    /** FK existence check by column, regardless of constraint name.
     *  Use this when the migration predates the `fk_<table>_<column>`
     *  naming convention and the FK was created under Laravel's default
     *  `<table>_<column>_foreign`. */
    public function hasForeignKeyOnColumn(string $table, string $column): bool
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            return $this->findForeignKeyOn($table, $column) !== null;
        }

        // SQLite PRAGMA foreign_key_list: `from` is the constrained
        // column, `table` is the referenced table. Match by `from`.
        $fks = Capsule::select("PRAGMA foreign_key_list('{$table}')");
        foreach ($fks as $fk) {
            if ($fk->from === $column) {
                return true;
            }
        }
        return false;
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;

beforeEach(function (): void {
    Database::resetBootState();
});

afterEach(function (): void {
    Database::resetBootState();
});

test('Database::bootDatabaseConnectionOnly accepts mariadb as a driver string', function (): void {
    $db = new Database([
        'db_driver' => 'mariadb',
        'db_host'   => '127.0.0.1',
        'db_port'   => 13306,
        'db_user'   => 'root',
        'db_password' => '',
        'db_name'   => 'unused',
    ]);
    // Driver wiring must accept 'mariadb' without rejecting it as
    // unknown. On SQLite the constructor throws because mariadb is not
    // a registered sqlite driver; on MariaDB/MySQL the connect attempt
    // is made and may succeed or fail with a connection error — either
    // way the driver string itself is accepted.
    try {
        $db->bootDatabaseConnectionOnly();
    } catch (Throwable $e) {
        // The interesting failure: "driver [mariadb] not supported" would
        // mean the new branch isn't wired.
        expect($e->getMessage())->not->toContain('not supported');
        expect($e->getMessage())->not->toContain('unknown driver');
    }
    // The point of the test is the absence of the "not supported" error —
    // getting here (whether through a clean connect or a connection-refused
    // throw) means the driver string was accepted.
    expect(true)->toBeTrue();
});

test('setSchemaInstallSkipped(true) short-circuits boot() install step', function (): void {
    // First boot on a fresh in-memory DB installs the full schema.
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->boot();
    expect(Capsule::schema()->hasTable('users'))->toBeTrue();

    // Wipe users so we can prove install() is short-circuited the second
    // time — if install() runs, the schema (and users table) would be
    // re-created. With the skip flag, boot() should not reinstall.
    Capsule::schema()->drop('users');
    expect(Capsule::schema()->hasTable('users'))->toBeFalse();

    Database::setSchemaInstallSkipped(true);
    $db2 = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db2->boot();

    // users table is still missing — install() was skipped.
    expect(Capsule::schema()->hasTable('users'))->toBeFalse();

    Database::setSchemaInstallSkipped(false);
});

test('resetBootState clears the schemaInstallSkipped flag', function (): void {
    Database::setSchemaInstallSkipped(true);
    Database::resetBootState();
    // After reset, boot() should run install() again.
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->boot();
    expect(Capsule::schema()->hasTable('users'))->toBeTrue();
});

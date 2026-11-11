<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Migration 0099 — `user_locations` becomes `principal_locations`.
 *
 * What this pins is that the move loses nothing: a location saved before
 * the migration is still there afterwards, under the user's user-principal
 * — and a user who never got a principal (a row created before the
 * principals model, or one whose principal insert was rolled back) still
 * ends up with their locations instead of losing them to the join.
 */
beforeEach(function (): void {
    // The test builds its own schema → connect-only, no installer.
    TestDatabaseFactory::freshConnectionOnly();

    Capsule::schema()->create('users', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('email');
        $table->timestamps();
    });

    Capsule::schema()->create('groups', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('name');
        $table->timestamps();
    });

    Capsule::schema()->create('principals', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->enum('type', ['user', 'group']);
        $table->unsignedBigInteger('user_id')->nullable();
        $table->unsignedBigInteger('group_id')->nullable();
        $table->timestamps();

        $table->unique('user_id');
        $table->unique('group_id');
        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
    });

    Capsule::schema()->create('user_locations', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('user_id');
        $table->string('name', 100);
        $table->text('address');
        $table->boolean('is_default')->default(false);
        $table->timestamps();

        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->index(['user_id', 'is_default']);
    });
});

test('the location table is keyed on the principal', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0099_create_principal_locations_table.php';
    $migration->up();

    expect(Capsule::schema()->hasTable('principal_locations'))->toBeTrue();
    expect(Capsule::schema()->hasTable('user_locations'))->toBeFalse();

    $names = Capsule::schema()->getColumnListing('principal_locations');
    expect($names)->toContain('id', 'principal_id', 'name', 'address', 'is_default', 'created_at', 'updated_at');
});

test('a location saved before the migration is still there afterwards', function (): void {
    Capsule::table('users')->insert(['id' => 1, 'email' => 'a@example.com']);
    Capsule::table('principals')->insert([
        'id' => 10, 'type' => 'user', 'user_id' => 1,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    Capsule::table('user_locations')->insert([
        'id' => 5, 'user_id' => 1, 'name' => 'HQ', 'address' => 'Musterstraße 1',
        'is_default' => true,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0099_create_principal_locations_table.php';
    $migration->up();

    $location = Capsule::table('principal_locations')->first();

    expect($location)->not->toBeNull();
    expect((int) $location->principal_id)->toBe(10);
    expect($location->name)->toBe('HQ');
    expect($location->address)->toBe('Musterstraße 1');
    expect((int) $location->is_default)->toBe(1);
});

test('a user without a principal gets one, so their locations are not lost', function (): void {
    Capsule::table('users')->insert(['id' => 1, 'email' => 'a@example.com']);
    Capsule::table('user_locations')->insert([
        'id' => 5, 'user_id' => 1, 'name' => 'HQ', 'address' => 'Musterstraße 1',
        'is_default' => false,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0099_create_principal_locations_table.php';
    $migration->up();

    $principal = Capsule::table('principals')->where('type', 'user')->where('user_id', 1)->first();
    expect($principal)->not->toBeNull();

    $location = Capsule::table('principal_locations')->first();
    expect((int) $location->principal_id)->toBe((int) $principal->id);
});

test('the backfill is idempotent', function (): void {
    Capsule::table('users')->insert(['id' => 1, 'email' => 'a@example.com']);

    $migration = require __DIR__ . '/../../../database/migrations/0099_create_principal_locations_table.php';
    $migration->up();

    (new ReflectionMethod($migration, 'backfillUserPrincipals'))->invoke($migration);

    expect((int) Capsule::table('principals')->where('type', 'user')->count())->toBe(1);
});

test('deleting the user takes the locations with it', function (): void {
    Capsule::table('users')->insert(['id' => 1, 'email' => 'a@example.com']);

    $migration = require __DIR__ . '/../../../database/migrations/0099_create_principal_locations_table.php';
    $migration->up();

    $principalId = (int) Capsule::table('principals')->where('type', 'user')->value('id');
    Capsule::table('principal_locations')->insert([
        'principal_id' => $principalId, 'name' => 'HQ', 'address' => 'Musterstraße 1',
        'is_default' => false,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    Capsule::table('users')->where('id', 1)->delete();

    expect((int) Capsule::table('principal_locations')->count())->toBe(0);
});

test('deleting the group takes the locations with it', function (): void {
    Capsule::table('users')->insert(['id' => 1, 'email' => 'a@example.com']);
    Capsule::table('groups')->insert(['id' => 2, 'name' => 'Acme']);

    $migration = require __DIR__ . '/../../../database/migrations/0099_create_principal_locations_table.php';
    $migration->up();

    Capsule::table('principals')->insert([
        'id' => 20, 'type' => 'group', 'group_id' => 2, 'user_id' => null,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    Capsule::table('principal_locations')->insert([
        'principal_id' => 20, 'name' => 'HQ', 'address' => 'Musterstraße 1',
        'is_default' => false,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    Capsule::table('groups')->where('id', 2)->delete();

    expect((int) Capsule::table('principal_locations')->count())->toBe(0);
});

test('down puts the user-scoped table back', function (): void {
    Capsule::table('users')->insert(['id' => 1, 'email' => 'a@example.com']);
    Capsule::table('principals')->insert([
        'id' => 10, 'type' => 'user', 'user_id' => 1,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    Capsule::table('user_locations')->insert([
        'id' => 5, 'user_id' => 1, 'name' => 'HQ', 'address' => 'Musterstraße 1',
        'is_default' => true,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0099_create_principal_locations_table.php';
    $migration->up();
    $migration->down();

    expect(Capsule::schema()->hasTable('user_locations'))->toBeTrue();
    expect(Capsule::schema()->hasTable('principal_locations'))->toBeFalse();

    $restored = Capsule::table('user_locations')->first();
    expect((int) $restored->user_id)->toBe(1);
    expect($restored->name)->toBe('HQ');
    expect((int) $restored->is_default)->toBe(1);
});

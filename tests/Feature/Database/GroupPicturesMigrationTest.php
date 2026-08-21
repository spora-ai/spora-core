<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;

beforeEach(function (): void {
    Database::resetBootState();
    $db = new Database([
        'db_driver' => 'sqlite',
        'db_path'   => ':memory:',
    ]);
    $db->boot();
});

test('0068 migration creates the group_pictures table with the documented columns', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0068_create_group_pictures_table.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->hasTable('group_pictures'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('group_pictures', 'id'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('group_pictures', 'group_id'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('group_pictures', 'archetype'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('group_pictures', 'variant_key'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('group_pictures', 'palette_key'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('group_pictures', 'media_asset_id'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('group_pictures', 'created_at'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('group_pictures', 'updated_at'))->toBeTrue();
});

test('0068 migration does not disturb the existing agent_pictures schema', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0068_create_group_pictures_table.php';
    $migration->up();

    // The agent picture pipeline must keep working unchanged.
    expect(Capsule::schema()->hasTable('agent_pictures'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('agent_pictures', 'agent_id'))->toBeTrue();
});

test('0069 migration backfills a default group_pictures row for each pre-existing group', function (): void {
    // Seed a group + its principal. Migration 0067 is the one that
    // creates the groups table, so we manually insert the fixture rows
    // the way 0067 would.
    $userId = Capsule::table('users')->insertGetId([
        'email' => 'group-pic@example.com', 'username' => 'group_pic',
        'password' => 'unused-hash', 'status' => 1, 'verified' => 1,
        'roles_mask' => 0, 'registered' => time(),
    ]);
    $groupId = Capsule::table('groups')->insertGetId([
        'name' => 'Research', 'description' => null,
        'created_by_user_id' => $userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Capsule::table('group_memberships')->insert([
        'group_id' => $groupId, 'user_id' => $userId, 'role' => 'owner',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // Create the table first (0068), then run 0069's backfill.
    $create = require __DIR__ . '/../../../database/migrations/0068_create_group_pictures_table.php';
    $create->up();

    $backfill = require __DIR__ . '/../../../database/migrations/0069_backfill_default_group_pictures.php';
    $backfill->up();

    $row = Capsule::table('group_pictures')->where('group_id', $groupId)->first();
    expect($row)->not->toBeNull();
    expect($row->archetype)->toBe('collaborative');
    expect($row->palette_key)->toBe('slate');
    expect($row->variant_key)->toBeNull();
    expect($row->media_asset_id)->toBeNull();
});

test('0069 migration is idempotent — re-running does not create duplicate rows', function (): void {
    $userId = Capsule::table('users')->insertGetId([
        'email' => 'idem@example.com', 'username' => 'idem',
        'password' => 'unused-hash', 'status' => 1, 'verified' => 1,
        'roles_mask' => 0, 'registered' => time(),
    ]);
    $groupId = Capsule::table('groups')->insertGetId([
        'name' => 'Idempotent', 'description' => null,
        'created_by_user_id' => $userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $create = require __DIR__ . '/../../../database/migrations/0068_create_group_pictures_table.php';
    $create->up();
    $backfill = require __DIR__ . '/../../../database/migrations/0069_backfill_default_group_pictures.php';

    $backfill->up();
    $backfill->up();

    expect(Capsule::table('group_pictures')->where('group_id', $groupId)->count())->toBe(1);
});

test('group_pictures row is CASCADE-deleted when its group is deleted', function (): void {
    $userId = Capsule::table('users')->insertGetId([
        'email' => 'cascade@example.com', 'username' => 'cascade',
        'password' => 'unused-hash', 'status' => 1, 'verified' => 1,
        'roles_mask' => 0, 'registered' => time(),
    ]);
    $groupId = Capsule::table('groups')->insertGetId([
        'name' => 'Cascade', 'description' => null,
        'created_by_user_id' => $userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $create = require __DIR__ . '/../../../database/migrations/0068_create_group_pictures_table.php';
    $create->up();
    Capsule::table('group_pictures')->insert([
        'group_id' => $groupId, 'archetype' => 'collaborative',
        'variant_key' => null, 'palette_key' => 'slate', 'media_asset_id' => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    Capsule::table('groups')->where('id', $groupId)->delete();

    expect(Capsule::table('group_pictures')->where('group_id', $groupId)->count())->toBe(0);
});

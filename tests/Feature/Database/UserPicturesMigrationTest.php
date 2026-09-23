<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\UserPicture;

/**
 * Schema tests for `user_pictures`. The migration is loaded by
 * `TestDatabaseFactory::freshDatabase()` (every test gets its own
 * fresh DB because DDL can't be rolled back inside a transaction).
 * The tests then assert the post-migration invariants directly
 * against the schema — same pattern as
 * {@see Tests\Feature\Database\GroupPicturesMigrationTest}.
 */
beforeEach(function (): void {
    TestDatabaseFactory::freshDatabase();
});

test('0086 migration creates the user_pictures table with the documented columns', function (): void {
    expect(Capsule::schema()->hasTable('user_pictures'))->toBeTrue();

    $columns = Capsule::schema()->getColumnListing('user_pictures');
    foreach (['id', 'user_id', 'media_path', 'mime', 'size_bytes', 'created_at', 'updated_at'] as $expected) {
        expect($columns)->toContain($expected);
    }
});

test('0086 migration enforces UNIQUE on user_id — second insert with the same user throws', function (): void {
    // First row goes in fine. We use the AuthService::register helper
    // so the password NOT NULL is satisfied the same way the rest of
    // the test suite does it.
    $auth = bootAuthLayer();
    $userId = $auth->register('unique-test@example.com', 'Password1!', 'Unique Test');

    UserPicture::create([
        'user_id'    => $userId,
        'media_path' => 'user-pictures/' . $userId . '.png',
        'mime'       => 'image/png',
        'size_bytes' => 100,
    ]);

    // Second row for the same user must violate the UNIQUE constraint.
    expect(static fn(): UserPicture => UserPicture::create([
        'user_id'    => $userId,
        'media_path' => 'user-pictures/' . $userId . '.jpg',
        'mime'       => 'image/jpeg',
        'size_bytes' => 200,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

test('0086 migration cascades on user delete', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('cascade-test@example.com', 'Password1!', 'Cascade Test');

    UserPicture::create([
        'user_id'    => $userId,
        'media_path' => 'user-pictures/' . $userId . '.png',
        'mime'       => 'image/png',
        'size_bytes' => 100,
    ]);

    expect(UserPicture::where('user_id', $userId)->exists())->toBeTrue();

    Capsule::table('users')->where('id', $userId)->delete();

    expect(UserPicture::where('user_id', $userId)->exists())->toBeFalse();
});

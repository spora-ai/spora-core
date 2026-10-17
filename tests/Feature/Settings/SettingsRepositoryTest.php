<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;

/**
 * @return array{0: SettingsRegistry, 1: SettingsRepository}
 */
function settingsRepositoryFixture(): array
{
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $repository = new SettingsRepository(new SecurityManager(str_repeat('k', 32)), $registry);

    return [$registry, $repository];
}

it('round-trips a non-secret value', function (): void {
    [$registry, $repository] = settingsRepositoryFixture();

    $repository->put($registry->get('SPORA_APP_NAME'), 'Spora Cloud', null);
    $rows = $repository->allForGroup('framework');

    expect($rows)->toHaveKey('SPORA_APP_NAME')
        ->and($rows['SPORA_APP_NAME']->value)->toBe('Spora Cloud')
        ->and($rows['SPORA_APP_NAME']->encrypted)->toBeFalse();
});

it('stores a secret encrypted rather than as plaintext', function (): void {
    [$registry, $repository] = settingsRepositoryFixture();

    $repository->put($registry->get('SPORA_MAIL_PASSWORD'), 'hunter2', null);

    $stored = Capsule::table('settings')->where('setting_key', 'SPORA_MAIL_PASSWORD')->first();

    expect($stored->value)->not->toBe('hunter2')
        ->and((bool) $stored->encrypted)->toBeTrue()
        ->and($repository->allForGroup('mail')['SPORA_MAIL_PASSWORD']->value)->toBe('hunter2');
});

/**
 * A fresh clone has no migrated schema and `db:reset` drops the table, so this
 * state is reachable on a running install. Throwing here is what makes Spora
 * unbootable — which is the failure the missing-table guard exists to prevent.
 * The repository has to be rebuilt for the assertion: `tableExists()` memoises
 * per instance, so the one that already saw the table would answer from cache.
 */
it('returns nothing when the settings table has never been migrated', function (): void {
    $migration = require BASE_PATH . '/database/migrations/0092_create_settings_table.php';

    Capsule::schema()->dropIfExists('settings');

    try {
        [, $repository] = settingsRepositoryFixture();

        expect($repository->allForGroup('mail'))->toBe([])
            ->and($repository->allForGroup('framework'))->toBe([]);
    } finally {
        $migration->up();
    }
});

it('reads back a new value rather than one cached from the previous read', function (): void {
    [$registry, $repository] = settingsRepositoryFixture();

    $repository->put($registry->get('SPORA_APP_NAME'), 'First', null);
    expect($repository->allForGroup('framework')['SPORA_APP_NAME']->value)->toBe('First');

    $repository->put($registry->get('SPORA_APP_NAME'), 'Second', null);

    expect($repository->allForGroup('framework')['SPORA_APP_NAME']->value)->toBe('Second')
        ->and(Capsule::table('settings')->where('setting_key', 'SPORA_APP_NAME')->count())->toBe(1);
});

it('does not return a row stored under another group', function (): void {
    [$registry, $repository] = settingsRepositoryFixture();

    $repository->put($registry->get('SPORA_MAIL_PASSWORD'), 'hunter2', null);

    expect(array_keys($repository->allForGroup('framework')))->not->toContain('SPORA_MAIL_PASSWORD')
        ->and(array_keys($repository->allForGroup('mail')))->toContain('SPORA_MAIL_PASSWORD');
});

/**
 * A row whose key is no longer declared is left over from an uninstalled
 * plugin or a setting that has since been renamed. Surfacing it would hand the
 * admin API a value for something the registry cannot render or validate.
 */
it('skips a stored key the registry no longer declares', function (): void {
    [, $repository] = settingsRepositoryFixture();

    Capsule::table('settings')->insert([
        'group_key'   => 'framework',
        'setting_key' => 'SPORA_REMOVED_IN_A_PREVIOUS_RELEASE',
        'value'       => '1',
        'encrypted'   => false,
        'updated_by'  => null,
        'created_at'  => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);

    expect($repository->allForGroup('framework'))->toBe([]);
});

it('deletes the row when a setting is written as null', function (): void {
    [$registry, $repository] = settingsRepositoryFixture();

    $repository->put($registry->get('SPORA_APP_NAME'), 'Spora Cloud', null);
    $repository->put($registry->get('SPORA_APP_NAME'), null, null);

    expect($repository->allForGroup('framework'))->toBe([]);
});

it('stores an empty string for a non-secret setting', function (): void {
    // The counterpart to the next test, and the reason that one is scoped to
    // secrets: `''` is a legitimate value here and must survive a round trip.
    [$registry, $repository] = settingsRepositoryFixture();

    $repository->put($registry->get('SPORA_APP_NAME'), '', null);

    expect($repository->allForGroup('framework'))->toHaveKey('SPORA_APP_NAME')
        ->and($repository->allForGroup('framework')['SPORA_APP_NAME']->value)->toBe('');
});

it('refuses to store an empty secret, because the ciphertext would be unreadable', function (): void {
    [$registry, $repository] = settingsRepositoryFixture();

    $repository->put($registry->get('SPORA_MAIL_PASSWORD'), '', null);

    // `encrypt('')` emits a bare nonce+MAC — 40 bytes — and `decrypt()`
    // rejects anything under 41 as too short. Storing it would produce a row
    // that reads as undecryptable forever, after which every read of this key
    // throws. The row must not exist at all.
    expect(Capsule::table('settings')->where('setting_key', 'SPORA_MAIL_PASSWORD')->exists())->toBeFalse();
});

it('reads back cleanly after a secret was cleared with an empty string', function (): void {
    [$registry, $repository] = settingsRepositoryFixture();

    $repository->put($registry->get('SPORA_MAIL_PASSWORD'), 'hunter2', null);
    $repository->put($registry->get('SPORA_MAIL_PASSWORD'), '', null);

    // The operator-visible symptom of the bug: a poisoned row makes every
    // read of the key throw, so the whole settings page fails to load.
    expect($repository->allForGroup('mail'))->toBe([]);
});

it('writes a key that already exists without violating the unique index', function (): void {
    // `updateOrInsert()` is SELECT-then-INSERT with no retry: MariaDB raised
    // "Duplicate entry 'mail-SPORA_MAIL_HOST'" against the unique index. An
    // atomic upsert removes the window entirely, and a second write has to
    // update the row rather than add one.
    [$registry, $repository] = settingsRepositoryFixture();

    $repository->put($registry->get('SPORA_MAIL_HOST'), 'smtp.example.com', null);
    $repository->put($registry->get('SPORA_MAIL_HOST'), 'other.example.com', null);

    $rows = Capsule::table('settings')->where('setting_key', 'SPORA_MAIL_HOST')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->value)->toBe('other.example.com');
});

it('keeps created_at across a second write', function (): void {
    [$registry, $repository] = settingsRepositoryFixture();

    Capsule::table('settings')->insert([
        'group_key'   => 'mail',
        'setting_key' => 'SPORA_MAIL_PORT',
        'value'       => '25',
        'encrypted'   => false,
        'updated_by'  => null,
        'created_at'  => '2020-01-01 00:00:00',
        'updated_at'  => '2020-01-01 00:00:00',
    ]);

    $repository->put($registry->get('SPORA_MAIL_PORT'), '587', null);

    $row = Capsule::table('settings')->where('setting_key', 'SPORA_MAIL_PORT')->first();

    expect((string) $row->created_at)->toStartWith('2020-01-01')
        ->and($row->value)->toBe('587');
});

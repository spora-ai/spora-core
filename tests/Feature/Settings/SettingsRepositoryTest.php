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

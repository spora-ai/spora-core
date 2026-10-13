<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Settings\SettingDefinition;
use Spora\Settings\SettingsRegistry;

function settingsRegistryFixture(): SettingsRegistry
{
    return new SettingsRegistry(new Paths(BASE_PATH));
}

/**
 * Every `$add()` writes into a map keyed by `$d->key`, so a key declared
 * twice is not a compile error and not a visible duplicate — the second
 * declaration silently replaces the first, and whichever `default` loses is
 * the one that disappears without trace. Comparing the keys written in the
 * source against the keys the registry hands back catches that, and catches a
 * definition added to the map by some other route.
 */
it('declares every setting key exactly once', function (): void {
    $registry = settingsRegistryFixture();

    $source = (string) file_get_contents((string) (new ReflectionClass(SettingsRegistry::class))->getFileName());
    preg_match_all("/key: '(SPORA_[A-Z0-9_]+)'/", $source, $matches);
    $declared = $matches[1];

    $repeated = array_keys(array_filter(
        array_count_values($declared),
        static fn(int $occurrences): bool => $occurrences > 1,
    ));

    expect($repeated)->toBe([])
        ->and(array_keys($registry->all()))->toEqualCanonicalizing($declared);
});

it('marks :key boot only, because it is read before the overlay runs', function (string $key): void {
    $definition = settingsRegistryFixture()->get($key);

    expect($definition)->not->toBeNull()
        ->and($definition->bootOnly)->toBeTrue();
})->with([
    'app env'          => 'SPORA_APP_ENV',
    'log path'         => 'SPORA_LOG_PATH',
    'db driver'        => 'SPORA_DB_DRIVER',
    'db path'          => 'SPORA_DB_PATH',
    'sqlite timeout'   => 'SPORA_SQLITE_BUSY_TIMEOUT',
    'db host'          => 'SPORA_DB_HOST',
    'db port'          => 'SPORA_DB_PORT',
    'db name'          => 'SPORA_DB_NAME',
    'db user'          => 'SPORA_DB_USER',
    'db password'      => 'SPORA_DB_PASSWORD',
    'secret key path'  => 'SPORA_KEY_PATH',
]);

it('marks every database-section setting boot only', function (): void {
    $notBootOnly = array_map(
        static fn(SettingDefinition $d): string => $d->key,
        array_filter(
            settingsRegistryFixture()->forSection('database'),
            static fn(SettingDefinition $d): bool => $d->bootOnly === false,
        ),
    );

    expect($notBootOnly)->toBe([]);
});

it('coerces a toggle from every string form a layer can hand it', function (string $raw, bool $expected): void {
    $registry = settingsRegistryFixture();
    $definition = $registry->get('SPORA_ALLOW_REGISTRATION');

    expect($registry->coerce($definition, $raw))->toBe($expected);
})->with([
    'one'    => ['1', true],
    'true'   => ['true', true],
    'yes'    => ['yes', true],
    'zero'   => ['0', false],
    'false'  => ['false', false],
    'no'     => ['no', false],
]);

it('coerces a number from the string form', function (): void {
    $registry = settingsRegistryFixture();

    expect($registry->coerce($registry->get('SPORA_LLM_TIMEOUT'), '42'))->toBe(42);
});

it('passes a text setting through unchanged', function (): void {
    $registry = settingsRegistryFixture();

    expect($registry->coerce($registry->get('SPORA_APP_NAME'), 'Spora Cloud'))->toBe('Spora Cloud');
});

/**
 * `SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES` is the allowlist behind
 * `MediaAllowedTypesService`, which the derivative pipeline treats as the
 * deliberate surface for images. An SVG served from it is script execution in
 * the operator's own origin. The exclusion used to live in
 * `ConfigMerger::parseImageTypesCsv()`; `coerceImageTypes()` inherited it when
 * ConfigMerger was deleted, and this is what keeps it there.
 */
it('rejects SVG however the allowlist spells it', function (string $raw): void {
    $registry = settingsRegistryFixture();

    expect($registry->coerce($registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'), $raw))->toBe([]);
})->with([
    'bare'          => 'svg',
    'uppercase'     => 'SVG',
    'mime suffix'   => 'svg+xml',
    'leading dot'   => '.svg',
    'padded'        => '  svg  ',
]);

it('drops every token when the whole allowlist is SVG', function (): void {
    $registry = settingsRegistryFixture();

    expect($registry->coerce($registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'), 'svg,SVG,.svg,svg+xml'))
        ->toBe([]);
});

it('drops SVG from the allowlist without discarding its neighbours', function (): void {
    $registry = settingsRegistryFixture();

    expect($registry->coerce($registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'), 'png,svg,webp'))
        ->toBe(['png', 'webp']);
});

it('accepts jpg and collapses it to jpeg', function (string $raw): void {
    $registry = settingsRegistryFixture();

    expect($registry->coerce($registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'), $raw))->toBe(['jpeg']);
})->with([
    'lowercase' => 'jpg',
    'uppercase' => 'JPG',
    'dotted'    => '.jpg',
]);

it('normalises whitespace, case and duplicates in the allowlist', function (): void {
    $registry = settingsRegistryFixture();

    expect($registry->coerce($registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'), " PNG, jpg ,jpeg\nwebp "))
        ->toBe(['png', 'jpeg', 'webp']);
});

it('drops SVG from a stored comma-separated allowlist too', function (): void {
    $registry = settingsRegistryFixture();
    $definition = $registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');

    expect($registry->decode($definition, 'svg,png,jpg'))->toBe(['png', 'jpeg']);
});

it('round-trips a :type setting through encode and decode', function (string $key, mixed $value, mixed $expected): void {
    $registry = settingsRegistryFixture();
    $definition = $registry->get($key);

    expect($registry->decode($definition, $registry->encode($value)))->toBe($expected);
})->with([
    'toggle true'  => ['SPORA_ALLOW_REGISTRATION', true, true],
    'toggle false' => ['SPORA_ALLOW_REGISTRATION', false, false],
    'number'       => ['SPORA_LLM_TIMEOUT', 42, 42],
    'text'         => ['SPORA_APP_NAME', 'Spora Cloud', 'Spora Cloud'],
    'list'         => ['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES', ['png', 'jpeg'], ['png', 'jpeg']],
    'empty list'   => ['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES', [], []],
]);

it('stores a list as JSON, not the comma-separated form operators type', function (): void {
    $registry = settingsRegistryFixture();

    expect($registry->encode(['png', 'jpeg']))
        ->toBe('["png","jpeg"]');
});

/**
 * A `null` default is excluded because null is how "not configured" is stored —
 * `SettingsRepository::put(null)` deletes the row rather than writing one — so
 * there is no null in the database to round-trip. A non-null default that
 * cannot survive its own encoding is a default the operator will never see
 * after they set it once.
 */
it('round-trips every non-null declared default', function (string $key): void {
    $registry = settingsRegistryFixture();
    $definition = $registry->get($key);

    expect($registry->decode($definition, $registry->encode($definition->default)))
        ->toEqual($definition->default);
})->with(array_keys(array_filter(
    settingsRegistryFixture()->all(),
    static fn(SettingDefinition $d): bool => $d->default !== null,
)));

it('finds a definition by key and reports an unknown one as absent', function (): void {
    $registry = settingsRegistryFixture();

    expect($registry->has('SPORA_MAIL_PORT'))->toBeTrue()
        ->and($registry->get('SPORA_MAIL_PORT'))->toBeInstanceOf(SettingDefinition::class)
        ->and($registry->has('SPORA_NOT_A_SETTING'))->toBeFalse()
        ->and($registry->get('SPORA_NOT_A_SETTING'))->toBeNull();
});

it('lists every secret-marked setting as a secret', function (): void {
    $registry = settingsRegistryFixture();

    expect($registry->secretKeys())
        ->toEqualCanonicalizing(array_keys(array_filter(
            $registry->all(),
            static fn(SettingDefinition $d): bool => $d->secret,
        )));
});

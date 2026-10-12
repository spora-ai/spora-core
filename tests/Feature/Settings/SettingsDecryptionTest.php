<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Config;
use Spora\Core\ConfigFactory;
use Spora\Core\Exceptions\SettingsDecryptionException;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;

/**
 * Seeds the row directly rather than through `put()`: the point is a row
 * encrypted under a key this process does not hold, which is what a lost or
 * rotated `SPORA_SECRET_KEY` leaves behind.
 *
 * @return array{0: SettingsRegistry, 1: SettingsRepository}
 */
function undecryptableSettingFixture(bool $readable = false): array
{
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $definition = $registry->get('SPORA_MAIL_PASSWORD');
    $writer = new SecurityManager(str_repeat($readable ? 'a' : 'b', 32));

    Capsule::table('settings')->insert([
        'group_key'   => $definition->group,
        'setting_key' => $definition->key,
        'value'       => $writer->encrypt('smtp-secret')->toStorageString(),
        'encrypted'   => true,
        'updated_by'  => null,
        'created_at'  => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);

    return [$registry, new SettingsRepository(new SecurityManager(str_repeat('a', 32)), $registry)];
}

/**
 * @return array{0: Config, 1: SettingsRegistry}
 */
function undecryptableConfigOverlay(bool $readable = false): array
{
    [$registry, $repository] = undecryptableSettingFixture($readable);

    $config = (new ConfigFactory($registry, new Paths(BASE_PATH)))->create();
    $config->applySettingsOverlay($repository, $registry);

    return [$config, $registry];
}

/**
 * The outage this guards against is a total one. A site whose SMTP password
 * cannot be decrypted cannot render the admin page that would explain why, so
 * throwing here turns one broken credential into a 500 on every request from
 * `Kernel::handle()` — including the ones that would have fixed it.
 *
 * `expect(...)->not->toThrow(Throwable::class)` is not used: `class_exists()`
 * is false for an interface, so Pest falls through to its message-matching
 * branch and the expectation passes whatever the closure does.
 */
it('completes the overlay when a stored secret cannot be decrypted', function (): void {
    [$registry, $repository] = undecryptableSettingFixture();

    try {
        (new ConfigFactory($registry, new Paths(BASE_PATH)))->create()
            ->applySettingsOverlay($repository, $registry);
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull($thrown instanceof Throwable
        ? sprintf('applySettingsOverlay() threw %s: %s', $thrown::class, $thrown->getMessage())
        : '');
});

it('falls back to the declared default rather than to an empty credential', function (): void {
    [$config, $registry] = undecryptableConfigOverlay();

    expect($config->all()['SPORA_MAIL_PASSWORD'])
        ->toBe($registry->get('SPORA_MAIL_PASSWORD')->default);
});

/**
 * Loud where it counts. Proceeding with an empty key turns "your secret key
 * was rotated" into an opaque 401 from a third-party API hours later with
 * nothing pointing back here.
 */
it('throws from every reader of an undecryptable key', function (string $method): void {
    [$config] = undecryptableConfigOverlay();

    expect(fn () => $config->{$method}('SPORA_MAIL_PASSWORD'))->toThrow(SettingsDecryptionException::class);
})->with([
    'get'       => 'get',
    'getString' => 'getString',
    'getInt'    => 'getInt',
    'getBool'   => 'getBool',
]);

it('names the undecryptable key so the admin API can list it', function (): void {
    [$config] = undecryptableConfigOverlay();

    expect($config->undecryptableKeys())->toBe(['SPORA_MAIL_PASSWORD']);
});

/**
 * Without this the throwing test could pass for the wrong reason — a reader
 * that threw on every key would satisfy it just as well.
 */
it('still serves a secret the current key can read', function (): void {
    [$config] = undecryptableConfigOverlay(readable: true);

    expect($config->all()['SPORA_MAIL_PASSWORD'])->toBe('smtp-secret')
        ->and($config->getString('SPORA_MAIL_PASSWORD'))->toBe('smtp-secret')
        ->and($config->undecryptableKeys())->toBe([]);
});
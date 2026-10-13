<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Settings\SettingsRegistry;

/**
 * `SPORA_MAIL_DRIVER` is the one setting whose declared options and effective
 * behaviour disagreed after the config migration.
 *
 * `SystemMailer` accepted four drivers — `smtp`, `php_mail`, `sendmail`,
 * `log` — and treated `php_mail` and `sendmail` as the same thing, mapping
 * both to `sendmail://default`. The registry originally declared only `smtp`
 * and `sendmail`, defaulted to `smtp`, and had no `log`. So an unconfigured
 * install resolved `php_mail`, which the admin page could not display, and a
 * `log` install could not be represented at all.
 */
it('canonicalises php_mail onto sendmail', function (): void {
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $driver = $registry->get('SPORA_MAIL_DRIVER');

    expect($registry->coerce($driver, 'php_mail'))->toBe('sendmail')
        ->and($registry->coerce($driver, ' PHP_MAIL '))->toBe('sendmail');
});

it('defaults to the driver an unconfigured install has always used', function (): void {
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $driver = $registry->get('SPORA_MAIL_DRIVER');

    // `sendmail`, not `smtp`: `php_mail` was the old fallback and both resolve
    // to `sendmail://default`. Changing this to `smtp` would point every
    // unconfigured install at localhost:587 on its first mail send.
    expect($driver->default)->toBe('sendmail');
});

it('offers exactly the drivers the mailer accepts', function (): void {
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $driver = $registry->get('SPORA_MAIL_DRIVER');

    $offered = array_column($driver->options, 'value');

    expect($offered)->toBe(['sendmail', 'smtp', 'log'])
        ->and($driver->default)->toBeIn($offered);
});

it('can represent the log driver it always accepted', function (): void {
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $driver = $registry->get('SPORA_MAIL_DRIVER');

    expect($registry->coerce($driver, 'log'))->toBe('log');
});

it('offers only the encryption values the mailer accepts', function (): void {
    // `SystemMailer::buildSmtpDsn()` throws for anything outside `tls`/`ssl`.
    // The registry is the authority the admin UI renders its dropdown from,
    // so an option it declares is one an operator can pick — and "None"
    // (value '') bricked every send once taken, with a 200 to confirm it.
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $encryption = $registry->get('SPORA_MAIL_ENCRYPTION');

    $offered = array_column($encryption->options, 'value');

    expect($offered)->toBe(['tls', 'ssl'])
        ->and($offered)->not->toContain('')
        ->and($offered)->toContain($encryption->default);
});

it('offers only drivers SystemMailer can build a DSN for', function (): void {
    // The same contract as the encryption set: an option the registry declares
    // is one the admin dropdown offers and an operator can pick.
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $offered  = array_column($registry->get('SPORA_MAIL_DRIVER')->options, 'value');

    expect($offered)->toContain('smtp', 'sendmail');
});

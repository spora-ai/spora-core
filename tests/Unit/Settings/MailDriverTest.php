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
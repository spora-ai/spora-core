<?php

declare(strict_types=1);

use Spora\Core\Config;
use Spora\Services\SystemMailer;

/**
 * `Address::__construct(string $address, string $name = '')` takes a
 * non-nullable name. Before the config migration the deleted
 * `SystemMailer::getMailConfig()` always supplied one, so the value was
 * effectively non-nullable by accident.
 *
 * After the migration it came from `Config::getString('SPORA_MAIL_FROM_NAME')`,
 * which answers `null` for a Config that does not carry the key — a TypeError
 * at send time. Unreachable in production because `ConfigFactory` populates
 * every declared key, which is exactly why it needs a test: nothing else
 * would notice it coming back.
 */
it('builds a from address when the config names neither field', function (): void {
    $mailer = mailerWithConfig(new Config());

    $address = captureFromAddress($mailer);

    expect($address)->not->toBeNull()
        ->and($address?->getAddress())->toBe('noreply@spora.local')
        ->and($address?->getName())->toBe('Spora');
});

it('uses the configured from address and name when both are present', function (): void {
    $mailer = mailerWithConfig(new Config([
        'SPORA_MAIL_FROM'      => 'hello@example.test',
        'SPORA_MAIL_FROM_NAME' => 'Example',
    ]));

    $address = captureFromAddress($mailer);

    expect($address?->getAddress())->toBe('hello@example.test')
        ->and($address?->getName())->toBe('Example');
});

it('renders a port into the smtp dsn even when the config omits it', function (): void {
    // Port 0 would silently produce smtp://host:0 and fail on connect with
    // nothing pointing at the cause. 587 is the registry's declared default.
    $dsn = captureSmtpDsn(new Config(['SPORA_MAIL_HOST' => 'smtp.example.test']));

    expect($dsn)->toContain(':587');
});

function mailerWithConfig(Config $config): SystemMailer
{
    $mailer = (new ReflectionClass(SystemMailer::class))->newInstanceWithoutConstructor();
    (new ReflectionClass(SystemMailer::class))->getProperty('config')->setValue($mailer, $config);

    return $mailer;
}

function captureFromAddress(SystemMailer $mailer): ?Symfony\Component\Mime\Address
{
    $method = (new ReflectionClass(SystemMailer::class))->getMethod('buildSender');

    return $method->invoke($mailer);
}

function captureSmtpDsn(Config $config): string
{
    $mailer = mailerWithConfig($config);
    $method = (new ReflectionClass(SystemMailer::class))->getMethod('buildSmtpDsn');

    return (string) $method->invoke($mailer);
}
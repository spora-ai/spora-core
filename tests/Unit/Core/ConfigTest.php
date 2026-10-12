<?php

declare(strict_types=1);

use Spora\Core\Config;
use Spora\Core\Paths;
use Spora\Settings\SettingsRegistry;

function configValueObjectFixture(): Config
{
    return new Config(
        [
            'SPORA_LLM_TIMEOUT'                   => '30',
            'SPORA_ALLOW_REGISTRATION'            => 'yes',
            'SPORA_APP_NAME'                      => 'Spora',
            'SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES' => ['png', 'jpeg'],
            'SPORA_DB_HOST'                       => null,
        ],
        [
            'SPORA_LLM_TIMEOUT'                   => Config::SOURCE_ENV,
            'SPORA_ALLOW_REGISTRATION'            => Config::SOURCE_CONFIG_FILE,
        ],
    );
}

it('reads an int from the string form a layer handed it', function (): void {
    expect(configValueObjectFixture()->getInt('SPORA_LLM_TIMEOUT'))->toBe(30);
});

it('reads the int 1 from the string "1"', function (): void {
    expect((new Config(['SPORA_MAIL_PORT' => '1']))->getInt('SPORA_MAIL_PORT'))->toBe(1);
});

it('reads a toggle from every truthy and falsy string form', function (string $raw, bool $expected): void {
    expect((new Config(['SPORA_ALLOW_REGISTRATION' => $raw]))->getBool('SPORA_ALLOW_REGISTRATION'))->toBe($expected);
})->with([
    'true'  => ['true', true],
    'one'   => ['1', true],
    'yes'   => ['yes', true],
    'zero'  => ['0', false],
    'empty' => ['', false],
]);

/**
 * `(string) ['png']` is the literal `"Array"`, and PHP emits a warning doing
 * it. A caller reading a list-valued key as a string has a type bug, and the
 * answer that surfaces it is the default — not a setting named "Array" that
 * flows into a URL.
 */
it('returns the default, not "Array", when a list-valued key is read as a string', function (): void {
    $config = configValueObjectFixture();
    $raised = [];
    set_error_handler(static function (int $errno, string $message) use (&$raised): bool {
        $raised[] = $message;

        return true;
    });

    try {
        $value = $config->getString('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES', 'fallback');
    } finally {
        restore_error_handler();
    }

    expect($value)->toBe('fallback')
        ->and($raised)->toBe([]);
});

it('returns the default for a key that is not set at all', function (): void {
    $config = configValueObjectFixture();

    expect($config->getString('SPORA_NOT_A_SETTING', 'fallback'))->toBe('fallback');
});

it('returns the default int for a non-numeric value', function (): void {
    $config = configValueObjectFixture();

    expect($config->getInt('SPORA_APP_NAME', 7))->toBe(7)
        ->and($config->getInt('SPORA_NOT_A_SETTING', 7))->toBe(7);
});

it('reports a key present only as a registry default as coming from the default layer', function (): void {
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $definition = $registry->get('SPORA_ALLOW_GROUP_CREATION');
    $config = new Config([$definition->key => $definition->default]);

    expect($config->sourceOf($definition->key))->toBe(Config::SOURCE_DEFAULT)
        ->and($config->getBool($definition->key))->toBe($definition->default);
});

it('reports the layer a value actually came from', function (): void {
    $config = configValueObjectFixture();

    expect($config->sourceOf('SPORA_LLM_TIMEOUT'))->toBe(Config::SOURCE_ENV)
        ->and($config->sourceOf('SPORA_ALLOW_REGISTRATION'))->toBe(Config::SOURCE_CONFIG_FILE)
        ->and($config->sourceOf('SPORA_NOT_A_SETTING'))->toBe(Config::SOURCE_DEFAULT);
});

it('reports a setting whose default is null as present', function (): void {
    $config = configValueObjectFixture();

    expect($config->has('SPORA_DB_HOST'))->toBeTrue()
        ->and($config->get('SPORA_DB_HOST'))->toBeNull()
        ->and($config->has('SPORA_NOT_A_SETTING'))->toBeFalse();
});
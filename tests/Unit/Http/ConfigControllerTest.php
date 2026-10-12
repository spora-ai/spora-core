<?php

declare(strict_types=1);

use Spora\Core\Config;
use Spora\Core\Paths;
use Spora\Http\ConfigController;
use Spora\Settings\SettingDefinition;
use Spora\Settings\SettingsRegistry;

/**
 * The Config `ConfigFactory` produces when `.env` and `config.php` are both
 * silent: every declared setting at its registry default. The flags this
 * endpoint reports have no defaults of their own any more — the registry owns
 * them — so a test that omitted a key would be asserting `false` for a toggle
 * the application ships as `true`.
 */
function runtimeFlagsAsShipped(): Config
{
    return new Config(array_map(
        static fn(SettingDefinition $d): mixed => $d->default,
        (new SettingsRegistry(new Paths(BASE_PATH)))->all(),
    ));
}

test('index() returns allow_registration true when nothing overrides the registry default', function (): void {
    $controller = new ConfigController(runtimeFlagsAsShipped());

    $response = $controller->index();

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeTrue();
});

test('index() returns allow_registration false when config sets it to false', function (): void {
    $controller = new ConfigController(new Config(['SPORA_ALLOW_REGISTRATION' => false]));

    $response = $controller->index();

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeFalse();
});

test('index() returns allow_registration true when config sets it to true', function (): void {
    $controller = new ConfigController(new Config(['SPORA_ALLOW_REGISTRATION' => true]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeTrue();
});

test('index() coerces truthy non-bool config values', function (): void {
    $controller = new ConfigController(new Config(['SPORA_ALLOW_REGISTRATION' => 1]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeTrue();
});

test('index() coerces falsy non-bool config values', function (): void {
    $controller = new ConfigController(new Config(['SPORA_ALLOW_REGISTRATION' => 0]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeFalse();
});

test('index() defaults plugin_install_enabled to false when nothing overrides the registry default', function (): void {
    $controller = new ConfigController(runtimeFlagsAsShipped());

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_install_enabled'])->toBeFalse();
});

test('index() returns plugin_install_enabled true when config sets it true', function (): void {
    $controller = new ConfigController(new Config(['SPORA_PLUGIN_INSTALL_ENABLED' => true]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_install_enabled'])->toBeTrue();
});

test('index() coerces plugin_install_enabled truthy non-bool values', function (): void {
    $controller = new ConfigController(new Config(['SPORA_PLUGIN_INSTALL_ENABLED' => 1]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_install_enabled'])->toBeTrue();
});

test('index() coerces plugin_install_enabled falsy non-bool values', function (): void {
    $controller = new ConfigController(new Config(['SPORA_PLUGIN_INSTALL_ENABLED' => 0]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_install_enabled'])->toBeFalse();
});

test('index() defaults plugin_catalog_enabled to true when nothing overrides the registry default', function (): void {
    $controller = new ConfigController(runtimeFlagsAsShipped());

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_catalog_enabled'])->toBeTrue();
});

test('index() returns plugin_catalog_enabled false when config sets it false', function (): void {
    $controller = new ConfigController(new Config(['SPORA_PLUGIN_CATALOG_ENABLED' => false]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_catalog_enabled'])->toBeFalse();
});

test('index() coerces plugin_catalog_enabled truthy non-bool values', function (): void {
    $controller = new ConfigController(new Config(['SPORA_PLUGIN_CATALOG_ENABLED' => 1]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_catalog_enabled'])->toBeTrue();
});

test('index() coerces plugin_catalog_enabled falsy non-bool values', function (): void {
    $controller = new ConfigController(new Config(['SPORA_PLUGIN_CATALOG_ENABLED' => 0]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_catalog_enabled'])->toBeFalse();
});

test('index() treats the string "false" as falsy (matches env-overlay semantics)', function (): void {
    // Regression: a naive `(bool)` cast turns `(bool) "false"` into `true`.
    // The runtime flag must agree with the FILTER_VALIDATE_BOOLEAN semantics
    // used when every layer is coerced, so the SPA never sees a value that
    // disagrees with the server's own gates.
    $controller = new ConfigController(new Config([
        'SPORA_ALLOW_REGISTRATION'      => 'false',
        'SPORA_PLUGIN_INSTALL_ENABLED'  => 'false',
        'SPORA_PLUGIN_CATALOG_ENABLED'  => 'false',
    ]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeFalse();
    expect($body['plugin_install_enabled'])->toBeFalse();
    expect($body['plugin_catalog_enabled'])->toBeFalse();
});

test('index() accepts FILTER_VALIDATE_BOOLEAN truthy strings', function (): void {
    $controller = new ConfigController(new Config([
        'SPORA_ALLOW_REGISTRATION'      => 'true',
        'SPORA_PLUGIN_INSTALL_ENABLED'  => '1',
        'SPORA_PLUGIN_CATALOG_ENABLED'  => 'yes',
    ]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeTrue();
    expect($body['plugin_install_enabled'])->toBeTrue();
    expect($body['plugin_catalog_enabled'])->toBeTrue();
});

test('index() falls back to false when a flag is an explicit null', function (): void {
    $controller = new ConfigController(new Config([
        'SPORA_ALLOW_REGISTRATION'      => null,
        'SPORA_PLUGIN_INSTALL_ENABLED'  => null,
        'SPORA_PLUGIN_CATALOG_ENABLED'  => null,
    ]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeFalse();
    expect($body['plugin_install_enabled'])->toBeFalse();
    expect($body['plugin_catalog_enabled'])->toBeFalse();
});

test('index() returns all three runtime feature flag keys together', function (): void {
    $controller = new ConfigController(new Config([
        'SPORA_ALLOW_REGISTRATION'      => true,
        'SPORA_PLUGIN_INSTALL_ENABLED'  => false,
        'SPORA_PLUGIN_CATALOG_ENABLED'  => true,
    ]));

    $response = $controller->index();

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body)->toHaveKeys(['allow_registration', 'plugin_install_enabled', 'plugin_catalog_enabled']);
});

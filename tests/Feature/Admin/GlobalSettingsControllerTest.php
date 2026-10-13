<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Core\Config;
use Spora\Core\Kernel;
use Spora\Core\Paths;
use Spora\Http\GlobalSettingsController;
use Spora\Http\Middleware\AdminMiddleware;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

function gsRegistry(): SettingsRegistry
{
    return new SettingsRegistry(new Paths(BASE_PATH));
}

function gsController(?Config $config = null): GlobalSettingsController
{
    $registry = gsRegistry();
    $config ??= new Config();

    return new GlobalSettingsController(
        $registry,
        new SettingsRepository(
            new Spora\Core\SecurityManager(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            $registry,
        ),
        $config,
        new AuthService(
            new Delight\Auth\Auth(new PDO('sqlite::memory:')),
            'https://host.test',
            '',
        ),
    );
}

/**
 * @param array<string, mixed> $body
 */
function gsPatch(GlobalSettingsController $controller, array $body): JsonResponse
{
    return $controller->update(new Request(
        [],
        [],
        [],
        [],
        [],
        [],
        json_encode($body, JSON_THROW_ON_ERROR),
    ));
}

function gsBody(JsonResponse $response): array
{
    return (array) json_decode($response->getContent(), true);
}

test('every admin settings route carries AdminMiddleware', function (): void {
    $collector = new Spora\OpenApi\RouteSpecCollector();
    Spora\Core\RouteDefinitions::register($collector);

    $routes = [];
    foreach ($collector->routes() as $route) {
        $routes[$route['method'] . ' ' . $route['route']] = $route['middleware'];
    }

    expect($routes['GET /api/v1/admin/settings'])->toBe([
        AuthMiddleware::class,
        CsrfMiddleware::class,
        AdminMiddleware::class,
    ])->and($routes['PATCH /api/v1/admin/settings'])->toBe([
        AuthMiddleware::class,
        CsrfMiddleware::class,
        AdminMiddleware::class,
    ])->and($routes['DELETE /api/v1/admin/settings/{group}/{key}'])->toBe([
        AuthMiddleware::class,
        CsrfMiddleware::class,
        AdminMiddleware::class,
    ]);
});

test('GET returns every declared setting, grouped by section', function (): void {
    $response = gsController()->index();
    $data = gsBody($response)['data'];

    expect($response->getStatusCode())->toBe(200);

    $keys = array_merge(...array_map(
        static fn(array $s): array => array_column($s['settings'], 'key'),
        $data['sections'],
    ));

    foreach (array_keys(gsRegistry()->all()) as $declared) {
        expect($keys)->toContain($declared);
    }
});

test('GET reports the source each value actually came from', function (): void {
    $config = new Config(
        ['SPORA_ALLOW_REGISTRATION' => false],
        ['SPORA_ALLOW_REGISTRATION' => Config::SOURCE_ENV],
    );

    $settings = collectSettings(gsController($config)->index());
    $row = $settings['SPORA_ALLOW_REGISTRATION'];

    expect($row['source'])->toBe('env')
        ->and($row['value'])->toBeFalse();
});

test('a boot-only setting is reported read-only', function (): void {
    $settings = collectSettings(gsController()->index());

    expect($settings['SPORA_DB_HOST']['readonly'])->toBeTrue()
        ->and($settings['SPORA_ALLOW_REGISTRATION']['readonly'])->toBeFalse();
});

test('GET never puts a plaintext secret on the wire', function (): void {
    $config = new Config(
        ['SPORA_MAIL_PASSWORD' => 'hunter2-the-real-password'],
        ['SPORA_MAIL_PASSWORD' => Config::SOURCE_DATABASE],
    );

    $response = gsController($config)->index();

    expect($response->getContent())->not->toContain('hunter2-the-real-password')
        ->and(collectSettings($response)['SPORA_MAIL_PASSWORD']['value'])->toBe('***');
});

test('PATCH rejects a key the registry does not declare', function (): void {
    $response = gsPatch(gsController(), ['settings' => [['key' => 'SPORA_MADE_UP', 'value' => 1]]]);

    expect($response->getStatusCode())->toBe(422)
        ->and(gsBody($response)['error']['code'])->toBe('VALIDATION_ERROR');
});

test('PATCH refuses to write a boot-only key', function (): void {
    // A DB-backed db_host would take effect on the request after the one that
    // wrote it, by which point the connection has already been made with the
    // old value.
    $response = gsPatch(gsController(), ['settings' => [['key' => 'SPORA_DB_HOST', 'value' => 'evil.example']]]);

    expect($response->getStatusCode())->toBe(422)
        ->and(gsBody($response)['error']['code'])->toBe('VALIDATION_ERROR');
});

test('PATCH enforces the declared validation regex', function (): void {
    $response = gsPatch(gsController(), [
        'settings' => [['key' => 'SPORA_MAIL_FROM', 'value' => 'not-an-address']],
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and(gsBody($response)['error']['code'])->toBe('VALIDATION_ERROR');
});

test('PATCH leaves a secret alone when the mask is submitted back unchanged', function (): void {
    $response = gsPatch(gsController(), [
        'settings' => [['key' => 'SPORA_MAIL_PASSWORD', 'value' => '***']],
    ]);

    expect(gsBody($response)['data']['updated'])->toBe([])
        ->and($response->getStatusCode())->toBe(200);
});

test('PATCH rejects a body with no settings array', function (): void {
    $response = gsPatch(gsController(), ['nope' => []]);

    expect($response->getStatusCode())->toBe(422);
});

/**
 * @return array<string, array<string, mixed>>
 */
function collectSettings(JsonResponse $response): array
{
    $out = [];
    foreach (gsBody($response)['data']['sections'] as $section) {
        foreach ($section['settings'] as $setting) {
            $out[$setting['key']] = $setting;
        }
    }

    return $out;
}
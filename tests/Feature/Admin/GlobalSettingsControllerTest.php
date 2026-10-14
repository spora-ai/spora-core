<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Core\Config;
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

test('GET exposes the storage group, which is not the section', function (): void {
    // DELETE /admin/settings/{group}/{key} is addressed by this, and the page
    // used to send the section key instead — which 422s for everything except
    // mail, because every other setting lives in `framework`.
    $settings = collectSettings(gsController()->index());

    expect($settings['SPORA_ALLOW_REGISTRATION']['group'])->toBe('framework')
        ->and($settings['SPORA_ALLOW_REGISTRATION']['section'] ?? 'access')->not->toBe('framework');
});

test('DELETE resets the key when addressed by its storage group', function (): void {
    $controller = gsController();

    $ok = $controller->reset('framework', 'SPORA_ALLOW_REGISTRATION');
    $wrong = $controller->reset('access', 'SPORA_ALLOW_REGISTRATION');

    expect($ok->getStatusCode())->toBe(200)
        ->and(gsBody($ok)['data']['reset'])->toBe(['SPORA_ALLOW_REGISTRATION'])
        ->and($wrong->getStatusCode())->toBe(422);
});

test('DELETE refuses a boot-only key', function (): void {
    $response = gsController()->reset('framework', 'SPORA_DB_HOST');

    expect($response->getStatusCode())->toBe(422);
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

/**
 * Why a setting is file-only is not the same reason twice: the database ones
 * are read before the connection that would hold them exists, the log level is
 * the diagnostic you reach for when the interface is broken, and a running
 * worker never re-reads its mode or its worker count. The refusal must not
 * depend on the controller knowing which reason applies, so it is asserted for
 * every file-only key from the registry's own list.
 *
 * The message is asserted too — a 422 from type validation would otherwise
 * satisfy a bare status check on, say, a toggle handed the string 'anything'.
 */
test('PATCH refuses every file-only setting, whatever the reason', function (string $key): void {
    $response = gsPatch(gsController(), ['settings' => [['key' => $key, 'value' => 'anything']]]);

    expect($response->getStatusCode())->toBe(422)
        ->and(gsBody($response)['error']['message'])->toContain('cannot be edited here');
})->with(gsRegistry()->bootOnlyKeys());

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

test('PATCH rejects a value outside the declared option set', function (): void {
    // The dropdown in the UI is rendered from `options`, so the API has to
    // agree with it. It did not: this returned 200, stored "nonsense", and
    // every subsequent mail send then threw in SystemMailer.
    $response = gsPatch(gsController(), [
        'settings' => [['key' => 'SPORA_MAIL_DRIVER', 'value' => 'carrier-pigeon']],
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and(gsBody($response)['error']['code'])->toBe('VALIDATION_ERROR');
});

test('PATCH accepts a legacy spelling and stores it canonically', function (): void {
    $controller = gsController();
    $response = gsPatch($controller, [
        'settings' => [['key' => 'SPORA_MAIL_DRIVER', 'value' => 'php_mail']],
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and(gsBody($response)['data']['updated'])->toBe(['SPORA_MAIL_DRIVER']);
});

test('PATCH refuses an array for a setting that takes one value', function (): void {
    // `coerce()` would have cast it with `(string)`, emitting "Array to string
    // conversion" and storing the literal string "Array".
    $response = gsPatch(gsController(), [
        'settings' => [['key' => 'SPORA_APP_NAME', 'value' => ['a', 'b']]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('a refused batch writes nothing at all', function (): void {
    $controller = gsController();

    $response = gsPatch($controller, [
        'settings' => [
            ['key' => 'SPORA_APP_NAME', 'value' => 'Accepted'],
            ['key' => 'SPORA_MAIL_DRIVER', 'value' => 'carrier-pigeon'],
        ],
    ]);

    expect($response->getStatusCode())->toBe(422);

    // The first entry validated cleanly and would have been written before the
    // second was rejected, leaving the operator unable to say what was saved.
    $registry = gsRegistry();
    $stored = (new SettingsRepository(
        new Spora\Core\SecurityManager(str_repeat('z', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        $registry,
    ))->allForGroup('framework');

    expect($stored)->not->toHaveKey('SPORA_APP_NAME');
});

test('PATCH rejects a malformed body without writing anything', function (): void {
    $controller = gsController();

    $response = $controller->update(new Request([], [], [], [], [], [], '{not json'));
    $body     = gsBody($response);

    expect($response->getStatusCode())->toBe(400)
        ->and($body['error']['code'])->toBe('INVALID_JSON');
});

test('PATCH rejects an entry that is not an object or carries no key', function (): void {
    $controller = gsController();

    expect(gsPatch($controller, ['settings' => ['a string']])->getStatusCode())->toBe(422)
        ->and(gsPatch($controller, ['settings' => [['value' => 1]]])->getStatusCode())->toBe(422);
});

test('PATCH refuses a scalar for a setting that takes a list', function (): void {
    // The mirror of the array-for-scalar case: this one would have been
    // `explode()`d into a single-entry list and stored as if the operator had
    // typed it.
    $response = gsPatch(gsController(), [
        'settings' => [['key' => 'SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES', 'value' => 'png']],
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and(gsBody($response)['error']['code'])->toBe('VALIDATION_ERROR');
});

<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Config;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MailConfigController;
use Spora\Services\SystemMailer;
use Spora\Settings\SettingDefinition;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    TestDatabaseFactory::boot();
});

afterEach(fn() => Spora\Core\Database::resetBootState());

const MAIL_CONFIG_URI = '/api/v1/mail-config';

/**
 * @return array{0: MailConfigController, 1: Spora\Auth\AuthService, 2: SystemMailer}
 */
function makeMailConfigController(Config $config = new Config()): array
{
    $authService = bootAuthLayer();
    $systemMailer = new SystemMailer($config);
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $settings = new SettingsRepository(new SecurityManager(str_repeat('k', 32)), $registry);
    $controller = new MailConfigController($authService, $systemMailer, $config, $settings, $registry);
    return [$controller, $authService, $systemMailer];
}

/**
 * @return array<string, mixed>
 */
function mailConfigFrom(MailConfigController $controller, Symfony\Component\HttpFoundation\JsonResponse $response): array
{
    $decoded = json_decode($response->getContent(), true);

    return $decoded['data']['mail_config'];
}

function storedMailSetting(string $key): ?object
{
    return Capsule::table('settings')->where('setting_key', $key)->first();
}

/**
 * What a fresh install's Config holds: the registry defaults, and nothing else.
 */
function mailConfigAsShipped(): Config
{
    return new Config(array_map(
        static fn(SettingDefinition $d): mixed => $d->default,
        (new SettingsRegistry(new Paths(BASE_PATH)))->all(),
    ));
}

// index

test('index() returns masked password when password is set', function (): void {
    [$controller] = makeMailConfigController(new Config([
        'SPORA_MAIL_DRIVER'   => 'smtp',
        'SPORA_MAIL_HOST'     => 'smtp.example.com',
        'SPORA_MAIL_PORT'     => 587,
        'SPORA_MAIL_USERNAME' => 'user',
        'SPORA_MAIL_PASSWORD' => 'secret',
        'SPORA_MAIL_FROM'     => 'from@example.com',
    ]));

    $response = $controller->index();

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_config']['password'])->toBe('***');
    expect($body['data']['mail_config']['username'])->toBe('user');
    expect($body['data']['mail_config']['host'])->toBe('smtp.example.com');
});

test('index() leaves password null when no password is set', function (): void {
    [$controller] = makeMailConfigController(new Config([
        'SPORA_MAIL_DRIVER' => 'php_mail',
    ]));

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_config']['password'])->toBeNull();
});

test('index() reports the registry defaults when nothing is configured', function (): void {
    // The endpoint used to answer with its own literals (`php_mail`, port 465)
    // rather than the site's mail configuration, because it asked for `mail_*`
    // keys no layer produced. It now reports what the Config holds, and a fresh
    // install holds the registry defaults.
    [$controller] = makeMailConfigController(mailConfigAsShipped());

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    // `sendmail`, not `smtp`. The endpoint's old literals were `php_mail` and
    // port 465; `php_mail` and `sendmail` are the same DSN, so the registry
    // default is the one that keeps an unconfigured install behaving exactly
    // as it did. See MailDriverTest.
    expect($body['data']['mail_config']['driver'])->toBe('sendmail');
    expect($body['data']['mail_config']['host'])->toBe('localhost');
    expect($body['data']['mail_config']['port'])->toBe(587);
    expect($body['data']['mail_config']['encryption'])->toBe('tls');
    expect($body['data']['mail_config']['from_name'])->toBe('Spora');
});

test('index() reports nulls for every field a Config that names none leaves out', function (): void {
    [$controller] = makeMailConfigController();

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_config']['driver'])->toBeNull();
    expect($body['data']['mail_config']['host'])->toBeNull();
    expect($body['data']['mail_config']['port'])->toBeNull();
    expect($body['data']['mail_config']['encryption'])->toBeNull();
    expect($body['data']['mail_config']['from_name'])->toBeNull();
});

test('index() reports a stored setting, not what .env or config.php says', function (): void {
    [$controller] = makeMailConfigController(new Config([
        'SPORA_MAIL_HOST' => 'from-env.example.com',
    ]));
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    (new SettingsRepository(new SecurityManager(str_repeat('k', 32)), $registry))
        ->put($registry->get('SPORA_MAIL_HOST'), 'from-db.example.com', null);

    $response = $controller->index();

    expect(mailConfigFrom($controller, $response)['host'])->toBe('from-db.example.com');
});

// update

test('update() returns 400 on invalid JSON', function (): void {
    [$controller] = makeMailConfigController();

    $request = Request::create(
        MAIL_CONFIG_URI,
        'PUT',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        'not json',
    );
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_JSON');
});

test('update() stores the submitted values in the settings table', function (): void {
    [$controller] = makeMailConfigController();

    $response = $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, [
        'driver'       => 'smtp',
        'host'         => 'smtp.example.com',
        'port'         => 465,
        'username'     => 'postmaster',
        'from_address' => 'noreply@example.com',
        'from_name'    => 'Example',
        'encryption'   => 'ssl',
    ]));

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect(storedMailSetting('SPORA_MAIL_HOST')->value)->toBe('smtp.example.com');
    expect(storedMailSetting('SPORA_MAIL_PORT')->value)->toBe('465');
    expect(storedMailSetting('SPORA_MAIL_FROM')->value)->toBe('noreply@example.com');

    // The response reports what was stored, not what was sent.
    expect(mailConfigFrom($controller, $response))->toMatchArray([
        'driver'       => 'smtp',
        'host'         => 'smtp.example.com',
        'port'         => 465,
        'username'     => 'postmaster',
        'from_address' => 'noreply@example.com',
        'from_name'    => 'Example',
        'encryption'   => 'ssl',
    ]);
});

test('update() leaves untouched fields at the value they already resolved to', function (): void {
    [$controller] = makeMailConfigController(new Config([
        'SPORA_MAIL_DRIVER'   => 'smtp',
        'SPORA_MAIL_HOST'     => 'smtp.example.com',
        'SPORA_MAIL_PORT'     => 587,
        'SPORA_MAIL_USERNAME' => 'postmaster',
        'SPORA_MAIL_FROM'     => 'noreply@example.com',
    ]));

    $response = $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, ['host' => 'relay.example.com']));

    expect(mailConfigFrom($controller, $response))->toMatchArray([
        'host'     => 'relay.example.com',
        'driver'   => 'smtp',
        'port'     => 587,
        'username' => 'postmaster',
    ]);
});

test('update() stores the password encrypted and never returns the plaintext', function (): void {
    [$controller] = makeMailConfigController();

    $response = $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, [
        'host'     => 'smtp.example.com',
        'password' => 'hunter2-correct-horse',
    ]));

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $stored = storedMailSetting('SPORA_MAIL_PASSWORD');
    expect($stored->value)->not->toBe('hunter2-correct-horse')
        ->and((bool) $stored->encrypted)->toBeTrue();

    $body = mailConfigFrom($controller, $response);
    expect($body['password'])->toBe('***');
    expect($response->getContent())->not->toContain('hunter2-correct-horse');
});

test('update() treats a masked password as unchanged', function (): void {
    [$controller] = makeMailConfigController();
    $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, ['password' => 'the-real-one']));

    $response = $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, [
        'host'     => 'smtp.example.com',
        'password' => '***',
    ]));

    // The row still holds the real value: writing the mask back would have
    // replaced it with the three characters the client was shown.
    expect(storedMailSetting('SPORA_MAIL_PASSWORD')->value)->not->toBe('***');
    expect(mailConfigFrom($controller, $response)['password'])->toBe('***');

    $after = $controller->index();
    expect($after->getContent())->not->toContain('the-real-one');
});

test('update() deletes the password row when it is sent as an empty string', function (): void {
    [$controller] = makeMailConfigController();
    $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, ['password' => 'the-real-one']));

    $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, ['password' => '']));

    // Not stored as an empty string: a secret is kept encrypted, and
    // `SecurityManager::decrypt()` refuses the 40-byte blob that encrypting ''
    // produces, so such a row would read as undecryptable forever. Clearing a
    // secret deletes its row.
    expect(storedMailSetting('SPORA_MAIL_PASSWORD'))->toBeNull();
});

test('a cleared password is reported as unset on the next request', function (): void {
    [$controller] = makeMailConfigController();
    $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, ['password' => 'the-real-one']));
    $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, ['password' => '']));

    // Must resolve from the file layers again rather than throwing the
    // "cannot be decrypted" error a stored '' would leave behind.
    [$nextRequest] = makeMailConfigController(mailConfigAsShipped());

    expect(mailConfigFrom($nextRequest, $nextRequest->index())['password'])->toBe('');
});

test('update() deletes a setting sent as null so it falls back to the file layers', function (): void {
    [$controller] = makeMailConfigController(new Config(['SPORA_MAIL_HOST' => 'from-env.example.com']));
    $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, ['host' => 'from-db.example.com']));

    $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, ['host' => null]));

    expect(storedMailSetting('SPORA_MAIL_HOST'))->toBeNull();

    // A fresh Config is what the next request gets. The request that deleted
    // the row keeps reporting it, because that row is where the value came
    // from and there is nothing left to look it up in.
    [$nextRequest] = makeMailConfigController(new Config(['SPORA_MAIL_HOST' => 'from-env.example.com']));

    expect(mailConfigFrom($nextRequest, $nextRequest->index())['host'])->toBe('from-env.example.com');
});

test('update() rejects a value the registry says is not a port', function (): void {
    [$controller] = makeMailConfigController();

    $response = $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, ['port' => 'not-a-port']));

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_SETTING_VALUE');
    expect(storedMailSetting('SPORA_MAIL_PORT'))->toBeNull();
});

test('update() rejects a driver outside the registry options', function (): void {
    [$controller] = makeMailConfigController();

    $response = $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, ['driver' => 'carrier-pigeon']));

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(storedMailSetting('SPORA_MAIL_DRIVER'))->toBeNull();
});

test('update() rejects a value that is not a single value at all', function (): void {
    [$controller] = makeMailConfigController();

    $response = $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, ['host' => ['a', 'b']]));

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(storedMailSetting('SPORA_MAIL_HOST'))->toBeNull();
});

test('update() writes nothing when one field of the request is rejected', function (): void {
    [$controller] = makeMailConfigController();

    // `driver` is validated before `port`; a request refused on `port` must not
    // leave the driver it accepted behind.
    $response = $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, [
        'driver' => 'smtp',
        'host'   => 'smtp.example.com',
        'port'   => 'not-a-port',
    ]));

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(Capsule::table('settings')->count())->toBe(0);
});

test('update() accepts the legacy php_mail driver and stores its canonical name', function (): void {
    [$controller] = makeMailConfigController();

    $response = $controller->update(jsonRequest('PUT', MAIL_CONFIG_URI, ['driver' => 'php_mail']));

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect(storedMailSetting('SPORA_MAIL_DRIVER')->value)->toBe('sendmail');
    expect(mailConfigFrom($controller, $response)['driver'])->toBe('sendmail');
});

test('update() returns 200 and reports the stored configuration for an empty body', function (): void {
    [$controller] = makeMailConfigController(new Config([
        'SPORA_MAIL_DRIVER' => 'smtp',
        'SPORA_MAIL_HOST'   => 'host',
        'SPORA_MAIL_FROM'   => 'noreply@example.com',
    ]));

    $request = jsonRequest('PUT', MAIL_CONFIG_URI, []);
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    // `null`, not a mask: no password is stored, and this endpoint used to answer
    // with a mask for a password that did not exist. `GET` reports the same.
    expect($body['data']['mail_config']['password'])->toBeNull();
    expect($body['data']['mail_config']['driver'])->toBe('smtp');
});

// test

test('test() returns 401 when no user is authenticated', function (): void {
    [$controller] = makeMailConfigController(new Config([
        'SPORA_MAIL_DRIVER' => 'log',
        'SPORA_MAIL_FROM'   => 'from@example.com',
    ]));
    clearSession();

    $response = $controller->test();

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('NOT_AUTHENTICATED');
});

test('test() returns 200 when log driver sends successfully', function (): void {
    [$controller, $authService] = makeMailConfigController(new Config([
        'SPORA_MAIL_DRIVER'     => 'log',
        'SPORA_MAIL_FROM'       => 'from@example.com',
        'SPORA_MAIL_FROM_NAME' => 'Spora',
    ]));
    bootAuth($authService, 'mailtest@example.com');

    $response = $controller->test();

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['message'])->toContain('Test email sent successfully');
});

test('test() returns 500 when mail driver is invalid', function (): void {
    [$controller, $authService] = makeMailConfigController(new Config([
        'SPORA_MAIL_DRIVER' => 'invalid_driver',
        'SPORA_MAIL_FROM'   => 'from@example.com',
    ]));
    bootAuth($authService, 'badmail@example.com');

    $response = $controller->test();

    expect($response->getStatusCode())->toBe(Response::HTTP_INTERNAL_SERVER_ERROR);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('MAIL_SEND_FAILED');
});

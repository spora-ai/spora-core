<?php

declare(strict_types=1);

use Spora\Core\Config;
use Spora\Core\Paths;
use Spora\Http\MailConfigController;
use Spora\Services\SystemMailer;
use Spora\Settings\SettingDefinition;
use Spora\Settings\SettingsRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    TestDatabaseFactory::boot();
});

afterEach(fn() => Spora\Core\Database::resetBootState());

const MAIL_CONFIG_URI = '/api/v1/mail-config';

function makeMailConfigController(Config $config = new Config()): array
{
    $authService = bootAuthLayer();
    $systemMailer = new SystemMailer($config);
    $controller = new MailConfigController($authService, $systemMailer, $config);
    return [$controller, $authService, $systemMailer];
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
    $asShipped = new Config(array_map(
        static fn(SettingDefinition $d): mixed => $d->default,
        (new SettingsRegistry(new Paths(BASE_PATH)))->all(),
    ));

    [$controller] = makeMailConfigController($asShipped);

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

// update — uses real DotenvWriter writes; we point at a tmp env path via reflection by overriding constants is impossible.
// Strategy: pass an empty body so DotenvWriter::sets is NOT called at all (no env values to write).

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

test('update() returns 200 with masked password when called with empty body', function (): void {
    [$controller] = makeMailConfigController(new Config([
        'SPORA_MAIL_DRIVER' => 'smtp',
        'SPORA_MAIL_HOST'   => 'host',
        'SPORA_MAIL_FROM'   => 'noreply@example.com',
    ]));

    $request = jsonRequest('PUT', MAIL_CONFIG_URI, []);
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_config']['password'])->toBe('***');
    expect($body['data']['mail_config']['driver'])->toBe('smtp');
});

test('update() with body containing only masked password skips writing it but still returns 200', function (): void {
    // Point DEFAULT_PATH override: not possible. But with body=['password'=>'***'], the code path
    // hits `continue;` so envValues stays empty — DotenvWriter::sets is never invoked.
    [$controller] = makeMailConfigController(new Config([
        'SPORA_MAIL_DRIVER' => 'smtp',
        'SPORA_MAIL_HOST'   => 'host',
        'SPORA_MAIL_FROM'   => 'noreply@example.com',
    ]));

    $request = jsonRequest('PUT', MAIL_CONFIG_URI, ['password' => '***']);
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_config']['password'])->toBe('***');
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

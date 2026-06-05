<?php

declare(strict_types=1);

use Spora\Http\MailConfigController;
use Spora\Services\SystemMailer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    (new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
});

afterEach(fn() => Spora\Core\Database::resetBootState());

const MAIL_CONFIG_URI = '/api/v1/mail-config';

function makeMailConfigController(array $config = []): array
{
    $authService = bootAuthLayer();
    $systemMailer = new SystemMailer($config);
    $controller = new MailConfigController($authService, $systemMailer, $config);
    return [$controller, $authService, $systemMailer];
}

// index

test('index() returns masked password when password is set', function (): void {
    [$controller] = makeMailConfigController([
        'mail_driver'   => 'smtp',
        'mail_host'     => 'smtp.example.com',
        'mail_port'     => 587,
        'mail_username' => 'user',
        'mail_password' => 'secret',
        'mail_from'     => 'from@example.com',
    ]);

    $response = $controller->index(new Request());

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_config']['password'])->toBe('***');
    expect($body['data']['mail_config']['username'])->toBe('user');
    expect($body['data']['mail_config']['host'])->toBe('smtp.example.com');
});

test('index() leaves password null when no password is set', function (): void {
    [$controller] = makeMailConfigController([
        'mail_driver' => 'php_mail',
    ]);

    $response = $controller->index(new Request());

    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_config']['password'])->toBeNull();
});

test('index() uses sane defaults when config keys are missing', function (): void {
    [$controller] = makeMailConfigController([]);

    $response = $controller->index(new Request());

    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_config']['driver'])->toBe('php_mail');
    expect($body['data']['mail_config']['port'])->toBe(587);
    expect($body['data']['mail_config']['encryption'])->toBe('tls');
    expect($body['data']['mail_config']['from_name'])->toBe('Spora');
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
    [$controller] = makeMailConfigController([
        'mail_driver' => 'smtp',
        'mail_host'   => 'host',
        'mail_from'   => 'noreply@example.com',
    ]);

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
    [$controller] = makeMailConfigController([
        'mail_driver' => 'smtp',
        'mail_host'   => 'host',
        'mail_from'   => 'noreply@example.com',
    ]);

    $request = jsonRequest('PUT', MAIL_CONFIG_URI, ['password' => '***']);
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_config']['password'])->toBe('***');
});

// test

test('test() returns 401 when no user is authenticated', function (): void {
    [$controller] = makeMailConfigController([
        'mail_driver' => 'log',
        'mail_from'   => 'from@example.com',
    ]);
    clearSession();

    $response = $controller->test(new Request());

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('NOT_AUTHENTICATED');
});

test('test() returns 200 when log driver sends successfully', function (): void {
    [$controller, $authService] = makeMailConfigController([
        'mail_driver' => 'log',
        'mail_from'   => 'from@example.com',
    ]);
    bootAuth($authService, 'mailtest@example.com');

    $response = $controller->test(new Request());

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['message'])->toContain('Test email sent successfully');
});

test('test() returns 500 when mail driver is invalid', function (): void {
    [$controller, $authService] = makeMailConfigController([
        'mail_driver' => 'invalid_driver',
        'mail_from'   => 'from@example.com',
    ]);
    bootAuth($authService, 'badmail@example.com');

    $response = $controller->test(new Request());

    expect($response->getStatusCode())->toBe(Response::HTTP_INTERNAL_SERVER_ERROR);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('MAIL_SEND_FAILED');
});

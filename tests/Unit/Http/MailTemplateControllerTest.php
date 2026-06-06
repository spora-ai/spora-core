<?php

declare(strict_types=1);

use Spora\Http\MailTemplateController;
use Spora\Models\MailTemplate;
use Spora\Services\MailTemplateService;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    (new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
});

afterEach(fn() => Spora\Core\Database::resetBootState());

const MAIL_TEMPLATES_URI = '/api/v1/mail-templates';

function makeMailTemplateController(): array
{
    $service = new MailTemplateService();
    $controller = new MailTemplateController($service);
    return [$controller, $service];
}

// index

test('index() returns the list of mail templates', function (): void {
    [$controller] = makeMailTemplateController();

    MailTemplate::create([
        'name' => 'custom_template',
        'subject' => 'Subj',
        'body_text' => 'Hello',
    ]);

    $response = $controller->index();

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_templates'])->toBeArray();
    $names = array_column($body['data']['mail_templates'], 'name');
    expect($names)->toContain('custom_template');
});

// store

test('store() creates a new template and returns 201', function (): void {
    [$controller] = makeMailTemplateController();

    $request = jsonRequest('POST', MAIL_TEMPLATES_URI, [
        'name' => 'a_new_template',
        'subject' => 'New Subject',
        'body_text' => 'Body text',
        'body_html' => '<p>Body html</p>',
    ]);
    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_template']['name'])->toBe('a_new_template');
    expect($body['data']['mail_template']['subject'])->toBe('New Subject');
});

test('store() returns 422 when required fields are missing', function (): void {
    [$controller] = makeMailTemplateController();

    $request = jsonRequest('POST', MAIL_TEMPLATES_URI, ['name' => 'incomplete']);
    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

test('store() returns 400 on invalid JSON', function (): void {
    [$controller] = makeMailTemplateController();

    $request = Symfony\Component\HttpFoundation\Request::create(
        MAIL_TEMPLATES_URI,
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        'not json',
    );
    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_JSON');
});

// show

test('show() returns 200 with the template by id', function (): void {
    [$controller] = makeMailTemplateController();
    $template = MailTemplate::create([
        'name' => 'show_test',
        'subject' => 'Subj',
        'body_text' => 'Hello',
    ]);

    $response = $controller->show($template->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_template']['name'])->toBe('show_test');
});

test('show() returns 404 for unknown id', function (): void {
    [$controller] = makeMailTemplateController();

    $response = $controller->show(99999);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

// update

test('update() modifies a template and returns 200', function (): void {
    [$controller] = makeMailTemplateController();
    $template = MailTemplate::create([
        'name' => 'before',
        'subject' => 'Old',
        'body_text' => 'Old text',
    ]);

    $request = jsonRequest('PUT', "/api/v1/mail-templates/{$template->id}", [
        'subject' => 'New Subj',
        'body_html' => '<b>html</b>',
    ]);
    $response = $controller->update($request, $template->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['mail_template']['subject'])->toBe('New Subj');
    expect($body['data']['mail_template']['body_html'])->toBe('<b>html</b>');
});

test('update() returns 404 for unknown id', function (): void {
    [$controller] = makeMailTemplateController();

    $request = jsonRequest('PUT', '/api/v1/mail-templates/99999', ['subject' => 'x']);
    $response = $controller->update($request, 99999);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

test('update() returns 400 on invalid JSON', function (): void {
    [$controller] = makeMailTemplateController();
    $template = MailTemplate::create(['name' => 'jsont', 'subject' => 's']);

    $request = Symfony\Component\HttpFoundation\Request::create(
        "/api/v1/mail-templates/{$template->id}",
        'PUT',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        'not json',
    );
    $response = $controller->update($request, $template->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
});

// destroy

test('destroy() returns 200 for non-system template', function (): void {
    [$controller] = makeMailTemplateController();
    $template = MailTemplate::create(['name' => 'custom_delete', 'subject' => 'x']);

    $response = $controller->destroy($template->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect(MailTemplate::find($template->id))->toBeNull();
});

test('destroy() returns 409 for email_verification system template', function (): void {
    [$controller] = makeMailTemplateController();
    $template = MailTemplate::create(['name' => 'email_verification', 'subject' => 'x']);

    $response = $controller->destroy($template->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_CONFLICT);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('CANNOT_DELETE_SYSTEM_TEMPLATE');
});

test('destroy() returns 409 for password_reset system template', function (): void {
    [$controller] = makeMailTemplateController();
    $template = MailTemplate::create(['name' => 'password_reset', 'subject' => 'x']);

    $response = $controller->destroy($template->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_CONFLICT);
});

test('destroy() returns 409 for welcome system template', function (): void {
    [$controller] = makeMailTemplateController();
    $template = MailTemplate::create(['name' => 'welcome', 'subject' => 'x']);

    $response = $controller->destroy($template->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_CONFLICT);
});

test('destroy() returns 404 for unknown id', function (): void {
    [$controller] = makeMailTemplateController();

    $response = $controller->destroy(99999);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

// preview

test('preview() returns 200 with rendered content', function (): void {
    [$controller] = makeMailTemplateController();
    MailTemplate::create([
        'name' => 'render_test',
        'subject' => 'Hello {{name}}',
        'body_text' => 'Welcome {{name}}',
        'body_html' => '<p>Welcome {{name}}</p>',
    ]);

    $request = Symfony\Component\HttpFoundation\Request::create('/api/v1/mail-templates/render_test/preview', 'GET', ['name' => 'Alice']);
    $response = $controller->preview($request, 'render_test');

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['subject'])->toBe('Hello Alice');
    expect($body['data']['body_text'])->toBe('Welcome Alice');
});

test('preview() returns 404 for unknown template name', function (): void {
    [$controller] = makeMailTemplateController();

    $request = Symfony\Component\HttpFoundation\Request::create('/api/v1/mail-templates/unknown/preview', 'GET');
    $response = $controller->preview($request, 'unknown');

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use Spora\Models\MailTemplate;

test('render substitutes known placeholders', function () {
    $template = new MailTemplate([
        'name' => 'test',
        'subject' => 'Hello {{user_name}}',
        'body' => 'Your email is {{email}}. Click {{verification_link}}',
        'body_html' => '<p>Hello {{user_name}}</p>',
    ]);

    $rendered = $template->render(['user_name' => 'Fabian', 'email' => 'fabian@test.com', 'verification_link' => 'https://example.com/verify']);

    expect($rendered->subject)->toBe('Hello Fabian');
    expect($rendered->bodyText)->toBe('Your email is fabian@test.com. Click https://example.com/verify' . "\n\nLinks:\n- https://example.com/verify");
    expect($rendered->bodyHtml)->toBe('<p>Hello Fabian</p>');
});

test('render leaves unknown placeholders intact', function () {
    $template = new MailTemplate(['name' => 'test', 'subject' => 'Hello {{unknown}}']);
    $rendered = $template->render(['user_name' => 'Fabian']);
    expect($rendered->subject)->toBe('Hello {{unknown}}');
});

test('render returns null fields as null', function () {
    $template = new MailTemplate([
        'name' => 'test',
        'subject' => 'Hello {{user_name}}',
        'body' => null,
        'body_html' => null,
    ]);

    $rendered = $template->render(['user_name' => 'Fabian']);

    expect($rendered->subject)->toBe('Hello Fabian');
    expect($rendered->bodyText)->toBe('');
    expect($rendered->bodyHtml)->toBe('');
});

test('render handles empty variables array', function () {
    $template = new MailTemplate([
        'name' => 'test',
        'subject' => 'Hello {{user_name}}',
        'body' => 'No variables here',
        'body_html' => '<p>No variables here</p>',
    ]);

    $rendered = $template->render([]);

    expect($rendered->subject)->toBe('Hello {{user_name}}');
    expect($rendered->bodyText)->toBe('No variables here');
    expect($rendered->bodyHtml)->toBe('<p>No variables here</p>');
});

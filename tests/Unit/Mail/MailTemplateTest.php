<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use Spora\Models\MailTemplate;

test('render substitutes known placeholders', function () {
    $template = new MailTemplate([
        'name' => 'test',
        'subject' => 'Hello {{user_name}}',
        'body_text' => 'Your email is {{email}}. Click {{verification_link}}',
        'body_html' => '<p>Hello {{user_name}}</p>',
    ]);

    $rendered = $template->render(['user_name' => 'Fabian', 'email' => 'fabian@test.com', 'verification_link' => 'https://example.com/verify']);

    expect($rendered['subject'])->toBe('Hello Fabian');
    expect($rendered['body_text'])->toBe('Your email is fabian@test.com. Click https://example.com/verify');
    expect($rendered['body_html'])->toBe('<p>Hello Fabian</p>');
});

test('render leaves unknown placeholders intact', function () {
    $template = new MailTemplate(['name' => 'test', 'subject' => 'Hello {{unknown}}']);
    $rendered = $template->render(['user_name' => 'Fabian']);
    expect($rendered['subject'])->toBe('Hello {{unknown}}');
});

test('render returns null fields as null', function () {
    $template = new MailTemplate([
        'name' => 'test',
        'subject' => 'Hello {{user_name}}',
        'body_text' => null,
        'body_html' => null,
    ]);

    $rendered = $template->render(['user_name' => 'Fabian']);

    expect($rendered['subject'])->toBe('Hello Fabian');
    expect($rendered['body_text'])->toBeNull();
    expect($rendered['body_html'])->toBeNull();
});

test('render handles empty variables array', function () {
    $template = new MailTemplate([
        'name' => 'test',
        'subject' => 'Hello {{user_name}}',
        'body_text' => 'No variables here',
        'body_html' => '<p>No variables here</p>',
    ]);

    $rendered = $template->render([]);

    expect($rendered['subject'])->toBe('Hello {{user_name}}');
    expect($rendered['body_text'])->toBe('No variables here');
    expect($rendered['body_html'])->toBe('<p>No variables here</p>');
});

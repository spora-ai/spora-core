<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mail;

use Spora\Services\Mail\MailTemplateRenderer;

function mailRenderer(): MailTemplateRenderer
{
    return MailTemplateRenderer::createDefault();
}

test('renders markdown to html when no html shell is provided', function () {
    $rendered = mailRenderer()->render([], 'subject', 'Hello **world**', null);

    expect($rendered->bodyHtml)->toContain('<strong>world</strong>');
    expect($rendered->bodyText)->toBe('Hello world');
});

test('injects rendered markdown into the {markdown_html} token of an html shell', function () {
    $rendered = mailRenderer()->render([], 'subject', 'a paragraph', '<h1>Hi</h1>{markdown_html}<p>Footer</p>');

    expect($rendered->bodyHtml)->toContain('<h1>Hi</h1>');
    expect($rendered->bodyHtml)->toContain('<p>a paragraph</p>');
    expect($rendered->bodyHtml)->toContain('<p>Footer</p>');
});

test('returns the html shell verbatim when no {markdown_html} token is present', function () {
    $rendered = mailRenderer()->render([], 'subject', 'a paragraph', '<h1>Hi</h1><p>Footer</p>');

    expect($rendered->bodyHtml)->toBe('<h1>Hi</h1><p>Footer</p>');
});

test('returns empty html and text when both body fields are null', function () {
    $rendered = mailRenderer()->render([], 'subject', null, null);

    expect($rendered->bodyHtml)->toBe('');
    expect($rendered->bodyText)->toBe('');
});

test('substitutes {{variables}} before rendering markdown', function () {
    $rendered = mailRenderer()->render(
        ['link' => 'https://x.test'],
        'subject',
        '[{{link}}]({{link}})',
        null,
    );

    expect($rendered->bodyHtml)->toContain('<a href="https://x.test">https://x.test</a>');
});

test('leaves unknown {{variables}} intact in the rendered html', function () {
    $rendered = mailRenderer()->render([], 'subject', 'Hello {{unknown}}', null);

    expect($rendered->bodyHtml)->toContain('{{unknown}}');
});

test('renders markdown links with href and link text', function () {
    $rendered = mailRenderer()->render([], 'subject', '[Click here](https://example.test/verify)', null);

    expect($rendered->bodyHtml)->toContain('href="https://example.test/verify"');
    expect($rendered->bodyText)->toContain('Click here');
    expect($rendered->bodyText)->toContain('https://example.test/verify');
});

test('renders GFM tables and strikethrough', function () {
    $body = <<<MD
        | col1 | col2 |
        | ---- | ---- |
        | a    | b    |

        ~~deleted~~
        MD;

    $rendered = mailRenderer()->render([], 'subject', $body, null);

    expect($rendered->bodyHtml)->toContain('<table>');
    expect($rendered->bodyHtml)->toContain('<del>deleted</del>');
});

test('returns the html shell verbatim when body is null and shell has no {markdown_html} token', function () {
    $rendered = mailRenderer()->render([], 'subject', null, '<p>just shell</p>');

    expect($rendered->bodyHtml)->toBe('<p>just shell</p>');
    expect($rendered->bodyText)->toBe('');
});

test('substitutes variables in the subject line including special characters', function () {
    $rendered = mailRenderer()->render(
        ['site_name' => "Spora's Test"],
        'Verify — {{site_name}}',
        null,
        null,
    );

    expect($rendered->subject)->toBe("Verify — Spora's Test");
});

test('treats empty markdown body the same as null when shell has no token', function () {
    $rendered = mailRenderer()->render([], 'subject', '', '<p>just shell</p>');

    expect($rendered->bodyHtml)->toBe('<p>just shell</p>');
});

test('substitutes the {markdown_html} token with empty string when body is null', function () {
    $rendered = mailRenderer()->render([], 'subject', null, '<header>{markdown_html}</header>');

    expect($rendered->bodyHtml)->toBe('<header></header>');
    expect($rendered->bodyHtml)->not->toContain('{markdown_html}');
});

test('escapes raw html in markdown input so payloads render as visible text', function () {
    $body = "<script>alert(1)</script>\n\nHello **world**";
    $rendered = mailRenderer()->render([], 'subject', $body, null);

    expect($rendered->bodyHtml)->not->toContain('<script');
    expect($rendered->bodyHtml)->toContain('&lt;script&gt;');
    expect($rendered->bodyHtml)->toContain('<strong>world</strong>');
});

test('blocks javascript: and vbscript: link schemes in markdown input', function () {
    $rendered = mailRenderer()->render(
        [],
        'subject',
        '[click](javascript:alert(1)) and [vbs](vbscript:alert(2))',
        null,
    );

    expect($rendered->bodyHtml)->not->toContain('javascript:');
    expect($rendered->bodyHtml)->not->toContain('vbscript:');
});

test('html-decodes href attribute values in the plain-text Links block', function () {
    $rendered = mailRenderer()->render(
        [],
        'subject',
        '[click](https://example.test/?a=1&b=2)',
        null,
    );

    expect($rendered->bodyText)->toContain('https://example.test/?a=1&b=2');
    expect($rendered->bodyText)->not->toContain('&amp;');
});

test('the renderer the container hands out escapes raw html like the fallback one', function () {
    // The container builds its own converter. It has to carry the same
    // escaping policy, or a runtime payload reaches the recipient's client
    // as markup on the production path and as text on the fallback one.
    $renderer = settingsAwareContainer()->get(MailTemplateRenderer::class);

    $rendered = $renderer->render(
        ['user_prompt' => '<img src=x onerror=alert(1)>'],
        'subject',
        'Prompt: {{user_prompt}}',
        null,
    );

    expect($rendered->bodyHtml)->not->toContain('<img')
        ->and($rendered->bodyHtml)->toContain('&lt;img');
});

test('the renderer the container hands out strips unsafe link schemes', function () {
    $renderer = settingsAwareContainer()->get(MailTemplateRenderer::class);

    $rendered = $renderer->render([], 'subject', '[click](javascript:alert(1))', null);

    expect($rendered->bodyHtml)->not->toContain('javascript:');
});

test('encodes a runtime value substituted into the html shell', function () {
    // The shell is the operator's markup; the value is a task prompt.
    $rendered = mailRenderer()->render(
        ['user_prompt' => '<script>alert(1)</script>'],
        'subject',
        null,
        '<p>{{user_prompt}}</p>',
    );

    expect($rendered->bodyHtml)->toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
});

test('encodes a runtime value that would otherwise close an attribute', function () {
    $rendered = mailRenderer()->render(
        ['user_prompt' => '" onmouseover="alert(1)'],
        'subject',
        null,
        '<p title="{{user_prompt}}">x</p>',
    );

    expect($rendered->bodyHtml)->not->toContain('onmouseover="alert(1)"')
        ->and($rendered->bodyHtml)->toContain('&quot;');
});

test('leaves the operator\'s own shell markup alone', function () {
    $rendered = mailRenderer()->render(
        ['user_prompt' => 'hello'],
        'subject',
        null,
        '<h1>Hi</h1><p class="lead">{{user_prompt}}</p>',
    );

    expect($rendered->bodyHtml)->toBe('<h1>Hi</h1><p class="lead">hello</p>');
});

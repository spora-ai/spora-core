<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\MediaEmbed;

/**
 * Contract guard for {@see MediaEmbed::fileCard()}.
 *
 * The card is static HTML that the chat UI's markdown sanitizer
 * (`spora-frontend/src/composables/useMarkdown.ts`) passes through. There
 * is no Vitest-level component test for it because there is no component:
 * these assertions ARE the DOMPurify contract. Anything the sanitizer
 * would strip — an icon element, `aria-hidden`, `download` — has to be
 * provably absent here, otherwise a frontend change silently guts the
 * markup with no failing test on the PHP side.
 *
 * The security assertions matter independently of rendering: the card's
 * `href` reaches `Content-Disposition` on the asset download route, and
 * the filename is echoed verbatim into that header, so a raw CR/LF in
 * either value would split the header.
 */

it('emits the whole card on a single line', function (): void {
    $card = MediaEmbed::fileCard('/api/v1/assets/abc.pdf', 'report.pdf', 12_400, 'application/pdf');

    expect($card)->not->toContain("\n")
        ->and($card)->not->toContain("\r");
});

it('emits the documented div > a > span structure', function (): void {
    expect(MediaEmbed::fileCard('/api/v1/assets/abc.pdf', 'report.pdf'))->toBe(
        '<div class="spora-file-card"><a class="spora-file-card__link" href="/api/v1/assets/abc.pdf">'
        . '<span class="spora-file-card__name">report.pdf</span></a></div>',
    );
});

it('renders byte size and MIME in a single meta span', function (): void {
    $card = MediaEmbed::fileCard('/api/v1/assets/abc.pdf', 'report.pdf', 12_400, 'application/pdf');

    expect($card)->toContain('<span class="spora-file-card__meta">12.1 KB · application/pdf</span>');
});

it('formats byte counts in binary units across every magnitude', function (int $size, string $expected): void {
    expect(MediaEmbed::fileCard('/api/v1/assets/a', 'a', $size))
        ->toContain($expected);
})->with([
    'bytes'         => [512, '512 B'],
    'kilobytes'     => [12_400, '12.1 KB'],
    'megabytes'     => [3 * 1024 * 1024, '3.0 MB'],
    'gigabytes'     => [2 * 1024 * 1024 * 1024, '2.0 GB'],
]);

it('omits the meta span entirely when neither size nor MIME is known', function (): void {
    $card = MediaEmbed::fileCard('/api/v1/assets/abc.pdf', 'report.pdf');

    expect($card)->not->toContain('spora-file-card__meta');
});

it('keeps the meta span when only one of size or MIME is known', function (): void {
    expect(MediaEmbed::fileCard('/u', 'f', 900))->toContain('spora-file-card__meta">900 B<');
    expect(MediaEmbed::fileCard('/u', 'f', null, 'text/markdown'))
        ->toContain('spora-file-card__meta">text/markdown<');
});

it('HTML-escapes the href', function (): void {
    $card = MediaEmbed::fileCard('/api/v1/assets/a?x=1&y=2', 'f');

    expect($card)->toContain('href="/api/v1/assets/a?x=1&amp;y=2"');
});

it('cannot be broken out of the href by a double quote', function (): void {
    $card = MediaEmbed::fileCard('/api/v1/assets/a" onmouseover="alert(1)', 'f');

    expect($card)->not->toContain('" onmouseover="alert(1)')
        ->and($card)->toContain('&quot; onmouseover=&quot;alert(1)');
    // Exactly one anchor, and its href attribute closes where we put it.
    expect(substr_count($card, '<a '))->toBe(1);
    expect(substr_count($card, 'href="'))->toBe(1);
});

it('HTML-escapes the filename', function (): void {
    $card = MediaEmbed::fileCard('/u', 'a<b>"c"&d.pdf');

    expect($card)->toContain('<span class="spora-file-card__name">a&lt;b&gt;&quot;c&quot;&amp;d.pdf</span>');
});

it('reduces a path-traversing filename to its basename', function (): void {
    expect(MediaEmbed::fileCard('/u', '../../etc/passwd'))
        ->toContain('spora-file-card__name">passwd<');
});

it('strips CR and LF from the href', function (): void {
    $card = MediaEmbed::fileCard("/api/v1/assets/a\r\nX-Injected: 1", 'f');

    expect($card)->not->toContain("\r")
        ->and($card)->not->toContain("\n")
        ->and($card)->toContain('href="/api/v1/assets/aX-Injected: 1"');
});

it('strips CR and LF from the filename', function (): void {
    $card = MediaEmbed::fileCard('/u', "re\r\nport.pdf");

    expect($card)->not->toContain("\r")
        ->and($card)->not->toContain("\n")
        ->and($card)->toContain('spora-file-card__name">report.pdf<');
});

it('falls back to a generic label when the filename is empty', function (): void {
    expect(MediaEmbed::fileCard('/u', ''))
        ->toContain('spora-file-card__name">download<');
});

it('carries no icon element and no aria-hidden, so nothing can be stripped', function (): void {
    // The download glyph is a CSS `::before` pseudo-element in
    // spora-frontend, not a node: `aria-hidden` is absent from the
    // sanitizer's ALLOWED_ATTR, so shipping one would be silently
    // stripped and the glyph announced to screen readers.
    $card = MediaEmbed::fileCard('/api/v1/assets/abc.pdf', 'report.pdf', 12_400, 'application/pdf');

    expect($card)->not->toContain('aria-hidden')
        ->and($card)->not->toContain('aria-label')
        ->and($card)->not->toContain('download=')
        ->and($card)->not->toContain('<svg')
        ->and($card)->not->toContain('<img')
        ->and($card)->not->toContain('<i ');
});

it('only uses tags and attributes the chat sanitizer already passes through', function (): void {
    // spora-frontend's useMarkdown.ts ALLOWED_TAGS / ALLOWED_ATTR. If one
    // of these stops being whitelisted the card silently loses structure,
    // so pin the exact vocabulary here.
    $card = MediaEmbed::fileCard('/api/v1/assets/abc.pdf', 'report.pdf', 12_400, 'application/pdf');

    preg_match_all('/<\/?([a-z0-9]+)/i', $card, $tags);
    expect(array_values(array_unique($tags[1])))->toBe(['div', 'a', 'span']);

    preg_match_all('/\s([a-z-]+)=/', $card, $attrs);
    expect(array_values(array_unique($attrs[1])))->toBe(['class', 'href']);
});

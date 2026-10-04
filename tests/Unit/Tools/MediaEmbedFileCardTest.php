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
    $card = MediaEmbed::fileCard('/api/v1/assets/abc.pdf', 'report.pdf', 12_400);

    expect($card)->not->toContain("\n")
        ->and($card)->not->toContain("\r");
});

it('emits the documented div > a > span structure', function (): void {
    expect(MediaEmbed::fileCard('/api/v1/assets/abc.pdf', 'report.pdf'))->toBe(
        '<div class="inline-flex max-w-120 my-[0.6rem] rounded-lg border border-foreground/10 bg-muted">'
        . '<a class="flex min-w-0 items-center gap-2.5 rounded-lg px-3 py-2 text-inherit no-underline'
        . ' transition-colors hover:bg-primary/10 focus-visible:outline-2 focus-visible:outline-offset-[-1px]'
        . ' focus-visible:outline-ring spora-file-card__glyph" href="/api/v1/assets/abc.pdf">'
        . '<span class="min-w-0 flex-auto truncate font-medium">report.pdf</span></a></div>',
    );
});

it('renders the byte size alone, and no MIME', function (): void {
    // The MIME used to sit after the size in the same span. A Word document's
    // is 71 characters of
    // `application/vnd.openxmlformats-officedocument.wordprocessingml.document`,
    // which overflowed the card and — because the meta span was
    // `flex-shrink: 0` — squeezed the filename to 0px, so the one part of the
    // card a user actually needs was the part that disappeared. The extension
    // on the filename already says what the file is, so the MIME bought
    // nothing and cost the name.
    $card = MediaEmbed::fileCard('/api/v1/assets/abc.docx', 'report.docx', 12_400);

    expect($card)->toContain('<span class="shrink-0 text-xs text-muted-foreground">12.1 KB</span>')
        ->and($card)->not->toContain('application/')
        ->and($card)->not->toContain('openxmlformats');
});

it('keeps the filename readable when the size is present', function (): void {
    // The regression that motivated dropping the MIME, pinned as markup: the
    // name carries the shrinking utilities, the size span cannot shrink, and
    // no third span competes for the row.
    $card = MediaEmbed::fileCard('/api/v1/assets/abc.docx', 'ai-agents-workshop-agenda.docx', 10_700);

    expect($card)->toContain('<span class="min-w-0 flex-auto truncate font-medium">ai-agents-workshop-agenda.docx</span>')
        ->and(substr_count($card, '<span'))->toBe(2);
});

it('registers every class it emits with the frontend, so none is silently dropped', function (): void {
    // The card is styled by Tailwind utilities that live in a PHP string, which
    // Tailwind's scanner never reads. spora-frontend makes them real with
    // `@source inline(...)` in src/style.css, and a class missing from that
    // list is not generated — no error, no warning, just an unstyled card.
    // This is the only thing standing between the two repos drifting apart.
    $style = @file_get_contents(
        dirname(__DIR__, 3) . '/../spora-frontend/src/style.css',
    );

    if ($style === false) {
        // The sibling checkout is optional in CI; the gate that matters runs
        // where both repos are present. Skipping beats a false pass.
        $this->markTestSkipped('spora-frontend/src/style.css is not reachable from this checkout.');
    }

    expect($style)->toMatch('/@source inline\("([^"]*)"\)/');

    preg_match('/@source inline\("([^"]*)"\)/', $style, $m);
    $registered = preg_split('/\s+/', trim($m[1]));

    preg_match_all('/class="([^"]*)"/', MediaEmbed::fileCard('/u', 'f.docx', 900), $emitted);
    $used = [];
    foreach ($emitted[1] as $attr) {
        foreach (preg_split('/\s+/', trim($attr)) as $class) {
            if ($class !== '') {
                $used[] = $class;
            }
        }
    }

    expect(array_values(array_diff(array_unique($used), $registered)))
        ->toBe([], 'every class the card emits must appear in the frontend @source inline() list');
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

it('omits the size span entirely when the size is unknown', function (): void {
    $card = MediaEmbed::fileCard('/api/v1/assets/abc.pdf', 'report.pdf');

    expect($card)->not->toContain('text-muted-foreground')
        ->and(substr_count($card, '<span'))->toBe(1);
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

    expect($card)->toContain('>a&lt;b&gt;&quot;c&quot;&amp;d.pdf</span>');
});

it('reduces a path-traversing filename to its basename', function (): void {
    expect(MediaEmbed::fileCard('/u', '../../etc/passwd'))
        ->toContain('>passwd</span>');
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
        ->and($card)->toContain('>report.pdf</span>');
});

it('falls back to a generic label when the filename is empty', function (): void {
    expect(MediaEmbed::fileCard('/u', ''))
        ->toContain('>download</span>');
});

it('carries no icon element and no aria-hidden, so nothing can be stripped', function (): void {
    // The download glyph is a CSS `::before` pseudo-element in
    // spora-frontend, not a node: `aria-hidden` is absent from the
    // sanitizer's ALLOWED_ATTR, so shipping one would be silently
    // stripped and the glyph announced to screen readers.
    $card = MediaEmbed::fileCard('/api/v1/assets/abc.pdf', 'report.pdf', 12_400);

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
    $card = MediaEmbed::fileCard('/api/v1/assets/abc.pdf', 'report.pdf', 12_400);

    preg_match_all('/<\/?([a-z0-9]+)/i', $card, $tags);
    expect(array_values(array_unique($tags[1])))->toBe(['div', 'a', 'span']);

    preg_match_all('/\s([a-z-]+)=/', $card, $attrs);
    expect(array_values(array_unique($attrs[1])))->toBe(['class', 'href']);
});

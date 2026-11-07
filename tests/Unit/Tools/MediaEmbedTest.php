<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Mockery;
use Spora\Models\MediaAsset;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Tools\MediaEmbed;

test('image() emits standard markdown image syntax with escaped alt', function (): void {
    $html = MediaEmbed::image('https://cdn/x.png', 'a "quoted" alt & more');

    expect($html)->toBe('![a &quot;quoted&quot; alt &amp; more](https://cdn/x.png)');
});

test('image() with empty alt emits bare image syntax', function (): void {
    expect(MediaEmbed::image('https://cdn/x.png'))->toBe('![](https://cdn/x.png)');
});

test('image() escapes Markdown metacharacters in alt to prevent injection', function (): void {
    // An untrusted alt containing "](javascript:alert(1))" would otherwise
    // break out of the ![alt](url) form and inject arbitrary URLs / Markdown.
    // After our escapes, the `]` is preceded by a backslash, so the
    // Markdown parser sees the alt as still-open rather than as a delimiter.
    $html = MediaEmbed::image('https://cdn/x.png', 'click ](javascript:alert(1)');

    // The output must NOT contain `](javascript:` immediately after the alt
    // opening `[` — that's the injection vector. The backslash is in between.
    expect($html)->toBe('![click \\](javascript:alert(1)](https://cdn/x.png)');
    // And in particular, an UNescaped `](javascript:` sequence must not appear.
    expect($html)->not->toMatch('/(?<!\\\\)]\\(javascript:alert\\(1\\)/');
});

test('image() escapes backslashes in alt', function (): void {
    // Backslash is the Markdown escape character — must be escaped first so it
    // can't neutralize the subsequent `]` / `[` escapes.
    $html = MediaEmbed::image('https://cdn/x.png', 'back\\slash');
    expect($html)->toBe('![back\\\\slash](https://cdn/x.png)');
});

test('audioFromUrl() emits an <audio controls> tag with the URL HTML-escaped', function (): void {
    $html = MediaEmbed::audioFromUrl('https://cdn/a.mp3?x=1&y=2');

    expect($html)->toBe('<audio controls preload="metadata" src="https://cdn/a.mp3?x=1&amp;y=2"></audio>');
});

test('videoFromUrl() emits width/height attributes when provided', function (): void {
    $html = MediaEmbed::videoFromUrl('https://cdn/v.mp4', 1920, 1080);

    expect($html)->toBe('<video controls preload="metadata" playsinline width="1920" height="1080" src="https://cdn/v.mp4"></video>');
});

test('videoFromUrl() omits width/height when null', function (): void {
    $html = MediaEmbed::videoFromUrl('https://cdn/v.mp4');

    expect($html)->toBe('<video controls preload="metadata" playsinline src="https://cdn/v.mp4"></video>');
});

test('audioFromBytes() routes through the supplied AssetStore', function (): void {
    $store = Mockery::mock(AssetStore::class);
    $store->shouldReceive('store')
        ->once()
        ->with('payload-bytes', 'audio/mpeg', 'speech.mp3')
        ->andReturn(new AssetReference('data:audio/mpeg;base64,cGRheA==', 'data_url'));

    $html = MediaEmbed::audioFromBytes('payload-bytes', $store, 'speech.mp3');

    expect($html)->toBe('<audio controls preload="metadata" src="data:audio/mpeg;base64,cGRheA=="></audio>');
});

test('videoFromBytes() routes through the supplied AssetStore with video/mp4 default', function (): void {
    $store = Mockery::mock(AssetStore::class);
    $store->shouldReceive('store')
        ->once()
        ->with('video-bytes', 'video/mp4', 'clip.mp4')
        ->andReturn(new AssetReference('/api/v1/assets/abc.mp4', 'local'));

    $html = MediaEmbed::videoFromBytes('video-bytes', $store, 'clip.mp4');

    expect($html)->toBe('<video controls preload="metadata" playsinline src="/api/v1/assets/abc.mp4"></video>');
});

test('liveEmbedLink() emits the line the model copies', function (): void {
    $asset = new MediaAsset();
    $asset->id = 'abc-123';
    $asset->filename = 'dashboard.html';

    $line = MediaEmbed::liveEmbedLink($asset, [
        'url'     => '/api/v1/media/abc-123/embed',
        'mime'    => 'text/html',
        'scripts' => true,
    ]);

    expect($line)->toContain('[dashboard.html](/api/v1/media/abc-123/embed)');
});

test('liveEmbedLink() says nothing when there is nothing to show live', function (): void {
    $asset = new MediaAsset();
    $asset->id = 'abc-123';
    $asset->filename = 'dashboard.html';

    expect(MediaEmbed::liveEmbedLink($asset, null))->toBe('');
});

test('liveEmbedLink() escapes a filename that holds markup', function (): void {
    // The upload path stores the operator's filename as it stands — only
    // the control characters are stripped — and the chat's sanitiser
    // keeps `a[href]` and `img[src]`. So markup in the name would reach
    // the operator's message as markup.
    $asset = new MediaAsset();
    $asset->id = 'abc-123';
    $asset->filename = '<a href="https://evil.example">x</a>.html';

    $line = MediaEmbed::liveEmbedLink($asset, [
        'url'     => '/api/v1/media/abc-123/embed',
        'mime'    => 'text/html',
        'scripts' => true,
    ]);

    expect($line)->not->toContain('<a href');
    expect($line)->toContain('&lt;a href=&quot;https://evil.example&quot;&gt;');
});

test('liveEmbedLink() escapes the markdown delimiters in the filename', function (): void {
    // A `]` would end the link's text early and let the rest of the name
    // be read as a target.
    $asset = new MediaAsset();
    $asset->id = 'abc-123';
    $asset->filename = 'a]b[c.html';

    $line = MediaEmbed::liveEmbedLink($asset, [
        'url'     => '/api/v1/media/abc-123/embed',
        'mime'    => 'text/html',
        'scripts' => true,
    ]);

    expect($line)->toContain('[a\]b\[c.html](/api/v1/media/abc-123/embed)');
});

test('liveEmbedLink() keeps the line on one line', function (): void {
    // The chat mounts the frame where the link is, and the link has to be
    // a line of its own.
    $asset = new MediaAsset();
    $asset->id = 'abc-123';
    $asset->filename = "a\r\nb.html";

    $line = MediaEmbed::liveEmbedLink($asset, [
        'url'     => '/api/v1/media/abc-123/embed',
        'mime'    => 'text/html',
        'scripts' => true,
    ]);

    expect($line)->toContain('[ab.html](/api/v1/media/abc-123/embed)');
});

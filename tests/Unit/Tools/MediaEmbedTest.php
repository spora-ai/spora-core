<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Mockery;
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

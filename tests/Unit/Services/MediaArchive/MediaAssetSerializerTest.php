<?php

declare(strict_types=1);

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaAssetSerializer;

function makeMediaAsset(array $attrs = []): MediaAsset
{
    $asset = new MediaAsset();
    $defaults = [
        'id'                  => '11111111-2222-3333-4444-555555555555',
        'agent_id'            => null,
        'task_id'             => null,
        'tool_call_id'        => null,
        'user_id'             => 1,
        'plugin_slug'         => null,
        'tool_name'           => null,
        'media_type'          => 'document',
        'mime_type'           => 'text/plain',
        'byte_size'           => 1024,
        'width'               => null,
        'height'              => null,
        'duration_seconds'    => null,
        'prompt'              => null,
        'filename'            => 'note.txt',
        'markdown_content'    => null,
        'tags'                => null,
        'metadata'            => null,
        'asset_url'           => null,
        'source_url'          => null,
        'storage_mode'        => 'inline',
        'upload_source'       => 'composer',
        'public_access_token' => null,
        'created_at'          => null,
        'updated_at'          => null,
    ];
    $asset->setRawAttributes(array_merge($defaults, $attrs), true);
    return $asset;
}

/**
 * Like makeMediaAsset(), but strips the JSON casts on tags/metadata
 * so we can feed raw Latin-1 / Windows-1252 bytes through them.
 */
function makeRawMediaAsset(array $attrs = []): MediaAsset
{
    $asset = makeMediaAsset();
    $castsProperty = new ReflectionProperty($asset, 'casts');
    $casts         = $castsProperty->getValue($asset);
    unset($casts['tags'], $casts['metadata']);
    $castsProperty->setValue($asset, $casts);
    foreach (['tags', 'metadata'] as $key) {
        if (array_key_exists($key, $attrs) && $attrs[$key] !== null) {
            $asset->setAttribute($key, $attrs[$key]);
        }
    }
    return $asset;
}

test('serialize() includes every documented wire field', function (): void {
    $serializer = new MediaAssetSerializer();
    $payload    = $serializer->serialize(makeMediaAsset(['filename' => 'hello.txt']));

    expect($payload)->toHaveKeys([
        'id', 'agent_id', 'task_id', 'tool_call_id', 'user_id',
        'plugin_slug', 'tool_name', 'media_type', 'mime_type',
        'byte_size', 'width', 'height', 'duration_seconds',
        'prompt', 'filename', 'markdown_content', 'tags', 'metadata',
        'asset_url', 'source_url', 'storage_mode', 'upload_source',
        'public_access_token', 'public_url', 'has_markdown',
        'created_at', 'updated_at',
    ]);

    expect($payload['filename'])->toBe('hello.txt');
    expect($payload['has_markdown'])->toBeFalse();
});

test('serialize() preserves valid UTF-8 strings untouched', function (): void {
    $serializer = new MediaAssetSerializer();
    $payload    = $serializer->serialize(makeMediaAsset([
        'filename'         => 'résumé-2026.pdf',
        'markdown_content' => 'Sévigné — été\n« café »',
    ]));

    expect($payload['filename'])->toBe('résumé-2026.pdf');
    expect($payload['markdown_content'])->toBe('Sévigné — été\n« café »');
});

test('serialize() fully-garbage Latin-1 strings are reinterpreted under Windows-1252', function (): void {
    // Three Latin-1-only bytes — iconv //IGNORE drops them all, so the Windows-1252 fallback must kick in.
    $latin1Bytes = chr(0xE9) . chr(0xFC) . chr(0xDF); // é ü ß
    expect(mb_check_encoding($latin1Bytes, 'UTF-8'))->toBeFalse();

    $serializer = new MediaAssetSerializer();
    $payload    = $serializer->serialize(makeMediaAsset(['filename' => $latin1Bytes]));

    expect(mb_check_encoding($payload['filename'], 'UTF-8'))->toBeTrue();
    expect($payload['filename'])->toBe('éüß');
});

test('serialize() never throws on completely unsalvageable bytes', function (): void {
    // 0xC0 is an overlong-encoding marker; iconv //IGNORE must drop it cleanly and stitch the rest back together.
    $garbled = "ok-" . chr(0xC0) . "-more";
    expect(mb_check_encoding($garbled, 'UTF-8'))->toBeFalse();

    $serializer = new MediaAssetSerializer();
    $payload    = $serializer->serialize(makeMediaAsset(['filename' => $garbled]));

    expect(mb_check_encoding($payload['filename'], 'UTF-8'))->toBeTrue();
    expect($payload['filename'])->toContain('ok-');
    expect($payload['filename'])->toContain('-more');
});

test('serialize() preserves valid UTF-8 around a single dropped byte', function (): void {
    // Mostly valid UTF-8 with one stray 0xE9 byte — iconv //IGNORE must drop it and keep the surrounding ASCII.
    $partial = "caf" . chr(0xE9) . ".txt";
    expect(mb_check_encoding($partial, 'UTF-8'))->toBeFalse();

    $serializer = new MediaAssetSerializer();
    $payload    = $serializer->serialize(makeMediaAsset(['filename' => $partial]));

    expect($payload['filename'])->toBe('caf.txt');
});

test('serialize() scrubs inside nested arrays (tags + metadata)', function (): void {
    $serializer = new MediaAssetSerializer();
    $payload    = $serializer->serialize(makeRawMediaAsset([
        'tags' => ["caf" . chr(0xE9), 'plain', chr(0xE9) . chr(0xFC) . chr(0xDF)],
        'metadata' => [
            'author'    => "François",
            'greeting'  => "Salut, " . chr(0xE9),
            'nested'    => ['deep' => "encore " . chr(0xE9) . chr(0xFC)],
            'numeric'   => 42,
            'boolean'   => true,
            'kept_list' => [1, 2, 'three'],
        ],
    ]));

    expect($payload['tags'])->toBe(['café', 'plain', 'éüß']);
    expect($payload['metadata']['author'])->toBe('François');
    expect($payload['metadata']['greeting'])->toBe('Salut, é');
    expect($payload['metadata']['nested']['deep'])->toBe('encore éü');
    expect($payload['metadata']['numeric'])->toBe(42);
    expect($payload['metadata']['boolean'])->toBeTrue();
    expect($payload['metadata']['kept_list'])->toBe([1, 2, 'three']);
});

test('serialize() preserves null values exactly', function (): void {
    $serializer = new MediaAssetSerializer();
    $payload    = $serializer->serialize(makeMediaAsset([
        'prompt'           => null,
        'markdown_content' => null,
        'tags'             => null,
        'metadata'         => null,
        'source_url'       => null,
    ]));

    expect($payload['prompt'])->toBeNull();
    expect($payload['markdown_content'])->toBeNull();
    expect($payload['tags'])->toBeNull();
    expect($payload['metadata'])->toBeNull();
    expect($payload['source_url'])->toBeNull();
});

test('serialize() produces JSON that json_encode accepts for the whole payload', function (): void {
    // The real failure path is `new JsonResponse($payload)` — exercised here without the Symfony response.
    $serializer = new MediaAssetSerializer();
    $payload    = $serializer->serialize(makeMediaAsset([
        'filename' => "weird " . chr(0x80) . " name " . chr(0xC0) . ".txt",
    ]));

    expect(fn(): string => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))->not()->toThrow(Throwable::class);
});

test('serialize() returns null public_url when no token is set', function (): void {
    $serializer = new MediaAssetSerializer();
    $payload    = $serializer->serialize(makeMediaAsset(['public_access_token' => null]));

    expect($payload['public_url'])->toBeNull();
});

test('serialize() uses the caller-supplied base URL as-is — no scheme prefix is added', function (): void {
    // Regression: callers pass Request::getSchemeAndHttpHost() (already
    // includes the scheme). The previous implementation treated that
    // value as a bare host and prepended another scheme, producing
    // `https://https://spora.example/...` share links.
    $serializer = new MediaAssetSerializer();
    $payload    = $serializer->serialize(
        makeMediaAsset(['public_access_token' => 'tok-abc']),
        'https://spora.example',
    );

    expect($payload['public_url'])->toBe(
        'https://spora.example/api/v1/public/media/11111111-2222-3333-4444-555555555555?token=tok-abc',
    );
});

test('serialize() strips a trailing slash from the caller-supplied base URL', function (): void {
    $serializer = new MediaAssetSerializer();
    $payload    = $serializer->serialize(
        makeMediaAsset(['public_access_token' => 'tok-abc']),
        'https://spora.example/',
    );

    expect($payload['public_url'])->toStartWith('https://spora.example/api/v1/public/media/');
    expect($payload['public_url'])->not->toContain('//api');
});

test('serialize() falls back to HTTP_HOST + HTTPS when no base URL is supplied', function (): void {
    $previous   = $_SERVER['HTTP_HOST'] ?? null;
    $previousSsl = $_SERVER['HTTPS'] ?? null;
    $_SERVER['HTTP_HOST'] = 'fallback.example';
    $_SERVER['HTTPS']     = 'on';

    try {
        $serializer = new MediaAssetSerializer();
        $payload    = $serializer->serialize(makeMediaAsset(['public_access_token' => 'tok-abc']));

        expect($payload['public_url'])->toBe(
            'https://fallback.example/api/v1/public/media/11111111-2222-3333-4444-555555555555?token=tok-abc',
        );
    } finally {
        if ($previous === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $previous;
        }
        if ($previousSsl === null) {
            unset($_SERVER['HTTPS']);
        } else {
            $_SERVER['HTTPS'] = $previousSsl;
        }
    }
});

test('serialize() returns null public_url when no base URL is supplied and HTTP_HOST is empty', function (): void {
    $previous = $_SERVER['HTTP_HOST'] ?? null;
    unset($_SERVER['HTTP_HOST']);

    try {
        $serializer = new MediaAssetSerializer();
        $payload    = $serializer->serialize(makeMediaAsset(['public_access_token' => 'tok-abc']));

        expect($payload['public_url'])->toBeNull();
    } finally {
        if ($previous !== null) {
            $_SERVER['HTTP_HOST'] = $previous;
        }
    }
});

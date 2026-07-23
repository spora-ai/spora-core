<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use ReflectionMethod;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\MediaArchive\PersistedAssetFields;

/**
 * Service-layer coverage for UTF-8 sanitisation inside
 * {@see MediaArchiveService}.
 *
 * The matching HTTP-layer tests that previously lived in
 * MediaArchiveUpdateTest ran the sanitizer through a JSON request body,
 * but `JSON_THROW_ON_ERROR` rejects malformed UTF-8 at the parse step
 * long before the decoder reaches the sanitizer — so those tests passed
 * for the wrong reason and never exercised the actual sanitisation.
 *
 * The sanitizer's real value is at the service / model layer: bytes
 * may flow in from non-HTTP sources (DB migrations, third-party tool
 * output, ingestion of payloads from older MySQL connections without
 * utf8mb4 defaults) where `json_decode` is never in the picture. The
 * tests below invoke the sanitisation points directly via reflection
 * and assert the bytes that come out are valid UTF-8.
 */
afterEach(function (): void {
    \Spora\Services\MediaArchive\MediaConverterDiscovery::reset();
});

test('applyFieldsToExisting sanitises Latin-1 bytes in filename to valid UTF-8', function (): void {
    $ctx = makeMediaArchiveService();
    try {
        // Seed a clean baseline row that the sanitiser will mutate.
        $asset = MediaAsset::create([
            'id'                                => '33333333-4444-5555-6666-777777777777',
            'asset_url'                         => '/api/v1/assets/33333333-4444-5555-6666-777777777777.png',
            'storage_mode'                      => 'external',
            'mime_type'                         => 'image/png',
            'media_type'                        => 'image',
            'byte_size'                         => 1024,
            'asset_token'                       => str_repeat('c', 32),
            'migrated_from_inline_data_url'     => false,
        ]);

        // Same fixture bytes as the deleted PATCH test — the intent
        // (Latin-1 bytes must be wiped before they reach the row) is
        // preserved, but the test now runs against the actual sanitiser
        // call site instead of through a JSON layer that throws the
        // bytes away at parse time.
        $dirty = 'résumé' . chr(0xE9) . chr(0xFC) . '.txt';
        $fields = new PersistedAssetFields(
            assetUrl: '/api/v1/assets/33333333-4444-5555-6666-777777777777.png',
            sourceUrl: null,
            storageMode: 'external',
            sniffedMime: 'image/png',
            mediaType: MediaType::Image,
            byteSize: 1024,
            width: null,
            height: null,
            durationSeconds: null,
            filename: $dirty,
            userId: 1,
            uploadSource: 'tool',
        );

        // applyFieldsToExisting is private; reach it via reflection so
        // we exercise the exact line that wraps the assignment in
        // Utf8Sanitizer::scrubString().
        $ref = new ReflectionMethod(MediaArchiveService::class, 'applyFieldsToExisting');
        $ref->invoke($ctx['service'], $asset, $fields);

        // Reload from the DB so the assertion sees the persisted value,
        // not the in-memory copy that fill() mutated.
        $persisted = MediaAsset::find($asset->id);

        expect($persisted->filename)->toBeString();
        expect(mb_check_encoding($persisted->filename, 'UTF-8'))->toBeTrue();
        // The solo Latin-1 bytes are gone; the salvage chain recovered
        // the rest of the string.
        expect($persisted->filename)->not->toContain(chr(0xE9));
        expect($persisted->filename)->not->toContain(chr(0xFC));
    } finally {
        $ctx['restore']();
    }
});

test('insertNew sanitises Latin-1 bytes in nested metadata to valid UTF-8', function (): void {
    $ctx = makeMediaArchiveService();
    try {
        // The metadata sanitisation happens inside insertNew() — which
        // takes the dirty array off the MediaIngestRequest, not the
        // PersistedAssetFields. Build a request whose metadata carries
        // raw Latin-1 bytes that json_encode would reject.
        $dirtyMetadata = [
            'title'  => 'café' . chr(0xE9),
            'nested' => ['note' => chr(0xFC) . 'ber'],
        ];
        $request = new MediaIngestRequest(
            bytes: 'hello',
            mime: 'text/plain',
            filename: 'sample.txt',
            metadata: $dirtyMetadata,
        );
        $fields = new PersistedAssetFields(
            assetUrl: '/api/v1/assets/44444444-5555-6666-7777-888888888888.txt',
            sourceUrl: null,
            storageMode: 'data_url',
            sniffedMime: 'text/plain',
            mediaType: MediaType::Document,
            byteSize: 5,
            width: null,
            height: null,
            durationSeconds: null,
            userId: 1,
            uploadSource: 'tool',
        );

        // insertNew is private; invoke via reflection so we test the
        // exact Utf8Sanitizer::scrub() call at the metadata slot.
        $ref = new ReflectionMethod(MediaArchiveService::class, 'insertNew');
        $asset = $ref->invoke($ctx['service'], $request, $fields);

        $persisted = MediaAsset::find($asset->id);

        expect($persisted->metadata)->toBeArray();
        expect(mb_check_encoding($persisted->metadata['title'], 'UTF-8'))->toBeTrue();
        expect(mb_check_encoding($persisted->metadata['nested']['note'], 'UTF-8'))->toBeTrue();
        // The salvage chain recovered the surrounding ASCII from the
        // nested Latin-1 prefix.
        expect($persisted->metadata['nested']['note'])->toContain('ber');
    } finally {
        $ctx['restore']();
    }
});

<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaType;

/**
 * Pins the `MediaType` → `MediaEmbed` dispatch in
 * {@see Spora\Tools\MediaTool::embedForAsset()}.
 *
 * The `Document` arm is the new one: documents used to fall through to
 * `MediaEmbed::link()` and render as a bare markdown link, which was the
 * only unimplemented-looking case sitting next to a real `<audio>` player
 * and a real inline image. The rule is on the asset TYPE, not on which op
 * produced the asset, so `get_media` on an uploaded PDF and
 * `create_derivative` on the same PDF must look identical.
 *
 * The other three arms plus the `Unknown` fallback are asserted here too:
 * a change to one of them would be invisible in the happy-path feature
 * tests, which only cover the bucket each of them was written for.
 */

function documentEmbedTool(string $id, string $mime, string $mediaType): array
{
    $agentId = seedMediaToolAgent();
    $asset = seedMediaAsset(
        agentId: $agentId,
        userId: 99,
        mime: $mime,
        idOverride: $id,
    );
    Capsule::table('media_assets')
        ->where('id', $asset->id)
        ->update([
            'media_type' => $mediaType,
            'filename'   => 'quarterly-report.pdf',
            'byte_size'  => 12_400,
        ]);

    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());

    return [$tool, $agentId, $asset->id, $restore];
}

it('renders a document asset as a download card, not a markdown link', function (): void {
    [$tool, $agentId, $assetId, $restore] = documentEmbedTool(
        'a1000000-0000-4000-8000-000000000001',
        'application/pdf',
        'document',
    );

    try {
        $result = $tool->execute(
            ['action' => 'get_embed_code', 'asset_id' => $assetId],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        // The exact markup is pinned once, in MediaEmbedFileCardTest. What
        // matters here is that the Document branch of the dispatch is the one
        // that ran, and that it produced a card pointing at this asset.
        expect($result->content)
            ->toContain('href="/api/v1/assets/a1000000-0000-4000-8000-000000000001.pdf"')
            ->toContain('>quarterly-report.pdf</span>')
            ->toContain('>12.1 KB</span>')
            ->toContain('spora-file-card__glyph');
    } finally {
        $restore();
    }
});

it('buckets text/* mimes into the same document card as application/*', function (): void {
    // `MediaType::fromMime()` folds both prefixes into Document, so a
    // Markdown asset and a PDF asset must render identically.
    [$tool, $agentId, $assetId, $restore] = documentEmbedTool(
        'a1000000-0000-4000-8000-000000000002',
        'text/plain',
        'document',
    );

    try {
        $result = $tool->execute(
            ['action' => 'get_embed_code', 'asset_id' => $assetId],
            agentId: $agentId,
            userId: 99,
        );

        // Same card as the PDF, and no MIME: `text/plain` and
        // `application/pdf` must be indistinguishable here, which is the
        // point of the bucket.
        expect($result->content)->toContain('spora-file-card__glyph')
            ->and($result->content)->toContain('>quarterly-report.pdf</span>')
            ->and($result->content)->toContain('>12.1 KB</span>');
        expect($result->content)->not->toContain('text/plain');
    } finally {
        $restore();
    }
});

it('leaves the image arm on a markdown image embed', function (): void {
    [$tool, $agentId, $assetId, $restore] = documentEmbedTool(
        'a1000000-0000-4000-8000-000000000003',
        'image/png',
        'image',
    );

    try {
        $result = $tool->execute(
            ['action' => 'get_embed_code', 'asset_id' => $assetId],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->content)->toBe(
            '![quarterly-report.pdf](/api/v1/assets/a1000000-0000-4000-8000-000000000003.png)',
        );
    } finally {
        $restore();
    }
});

it('leaves the audio arm on an <audio> element', function (): void {
    [$tool, $agentId, $assetId, $restore] = documentEmbedTool(
        'a1000000-0000-4000-8000-000000000004',
        'audio/mpeg',
        'audio',
    );

    try {
        $result = $tool->execute(
            ['action' => 'get_embed_code', 'asset_id' => $assetId],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->content)->toBe(
            '<audio controls preload="metadata" src="/api/v1/assets/a1000000-0000-4000-8000-000000000004.mp3"></audio>',
        );
    } finally {
        $restore();
    }
});

it('leaves the video arm on a <video> element', function (): void {
    [$tool, $agentId, $assetId, $restore] = documentEmbedTool(
        'a1000000-0000-4000-8000-000000000005',
        'video/mp4',
        'video',
    );

    try {
        $result = $tool->execute(
            ['action' => 'get_embed_code', 'asset_id' => $assetId],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->content)->toBe(
            '<video controls preload="metadata" playsinline src="/api/v1/assets/a1000000-0000-4000-8000-000000000005.mp4"></video>',
        );
    } finally {
        $restore();
    }
});

it('keeps an unclassifiable asset on a plain markdown link', function (): void {
    // `Unknown` must NOT become a download card: there is no confirmed
    // type, so labelling it as a downloadable document would be a guess.
    [$tool, $agentId, $assetId, $restore] = documentEmbedTool(
        'a1000000-0000-4000-8000-000000000006',
        'application/octet-stream',
        'unknown',
    );

    try {
        $result = $tool->execute(
            ['action' => 'get_embed_code', 'asset_id' => $assetId],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->content)->toBe(
            '[quarterly-report.pdf](/api/v1/assets/a1000000-0000-4000-8000-000000000006)',
        );
    } finally {
        $restore();
    }
});

it('routes only a genuinely unclassifiable media_type to the link fallback', function (): void {
    // `MediaType::fromMime()` buckets EVERY `application/*` into
    // Document, so the link fallback is reachable only through the stored
    // `media_type` column (a null or unrecognised value), not through the
    // mime. Asserted here because that is the only thing keeping
    // `application/octet-stream` rows off the download card.
    expect(MediaType::fromMime('application/pdf'))->toBe(MediaType::Document)
        ->and(MediaType::fromMime('text/markdown'))->toBe(MediaType::Document)
        ->and(MediaType::fromMime('image/png'))->toBe(MediaType::Image)
        ->and(MediaType::fromMime('audio/mpeg'))->toBe(MediaType::Audio)
        ->and(MediaType::fromMime('video/mp4'))->toBe(MediaType::Video)
        ->and(MediaType::fromMime(null))->toBe(MediaType::Unknown);

    $asset = new MediaAsset();
    expect($asset->typedMediaType())->toBe(MediaType::Unknown);
    $asset->media_type = 'multipart';
    expect($asset->typedMediaType())->toBe(MediaType::Unknown);
    $asset->media_type = 'document';
    expect($asset->typedMediaType())->toBe(MediaType::Document);
});

it('keeps get_media and get_embed_code on the same card for one document', function (): void {
    // Both ops share `embedForAsset()`. If that ever forks, the same asset
    // would look different depending on which op surfaced it.
    [$tool, $agentId, $assetId, $restore] = documentEmbedTool(
        'a1000000-0000-4000-8000-000000000007',
        'application/pdf',
        'document',
    );

    try {
        $embedCode = $tool->execute(
            ['action' => 'get_embed_code', 'asset_id' => $assetId],
            agentId: $agentId,
            userId: 99,
        );
        $getMedia = $tool->execute(
            ['action' => 'get_media', 'asset_id' => $assetId],
            agentId: $agentId,
            userId: 99,
        );

        expect($getMedia->content)->toContain($embedCode->content);
    } finally {
        $restore();
    }
});

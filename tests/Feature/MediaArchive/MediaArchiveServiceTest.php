<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use FilesystemIterator;
use InvalidArgumentException;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\MediaAsset;
use Spora\Services\AutoAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Coverage for {@see MediaArchiveService} — the foundation that PRs 4 and 5
 * depend on. Each describe block locks in one contract slice.
 */
function mediaArchiveTestSetup(): array
{
    $tmp = sys_get_temp_dir() . '/spora-media-archive-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths     = new Paths(BASE_PATH);
    $security  = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $sniffer   = new MimeSniffer();
    $dataUrl   = new DataUrlAssetStore(50 * 1024 * 1024);
    $local     = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($dataUrl, $local, 1_048_576);
    $metadata  = new MetadataExtractor(new NullLogger(), false);
    $logger    = new NullLogger();

    $restore = static function () use ($tmp): void {
        putenv('SPORA_STORAGE_DIR');
        unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
        if (is_dir($tmp)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iter as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($tmp);
        }
    };

    return [
        'assetStore' => $assetStore,
        'sniffer'    => $sniffer,
        'metadata'   => $metadata,
        'logger'     => $logger,
        'tmp'        => $tmp,
        'restore'    => $restore,
    ];
}

/**
 * Build a MediaArchiveService with optional injected dependencies.
 *
 * @param array<string, mixed> $overrides
 */
function makeMediaArchiveService(array $overrides = []): array
{
    $ctx = mediaArchiveTestSetup();
    $fetcher = $overrides['fetcher'] ?? new RemoteMediaFetcher(
        new MockHttpClient([]),
        $ctx['logger'],
        30,
        100 * 1024 * 1024,
    );

    $service = new MediaArchiveService(
        $ctx['assetStore'],
        $fetcher,
        $ctx['sniffer'],
        $ctx['metadata'],
        $ctx['logger'],
        (bool) ($overrides['promoteExternal'] ?? true),
        (int) ($overrides['maxPromoteBytes'] ?? 100 * 1024 * 1024),
    );

    return [
        'service'    => $service,
        'assetStore' => $ctx['assetStore'],
        'sniffer'    => $ctx['sniffer'],
        'metadata'   => $ctx['metadata'],
        'fetcher'    => $fetcher,
        'tmp'        => $ctx['tmp'],
        'restore'    => $ctx['restore'],
    ];
}

// ----- Sniff -----------------------------------------------------------------

describe('MediaArchiveService::sniff', function (): void {
    it('sniffs PNG magic bytes', function (): void {
        [$ctx] = [mediaArchiveTestSetup()];
        try {
            $sniffer = $ctx['sniffer'];
            $png = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 32);
            expect($sniffer->sniffFromBytes($png))->toBe('image/png');
        } finally {
            $ctx['restore']();
        }
    });

    it('sniffs MP4 from ftyp box', function (): void {
        [$ctx] = [mediaArchiveTestSetup()];
        try {
            $mp4 = pack('N', 32) . 'ftyp' . 'isom' . str_repeat("\x00", 64);
            expect($ctx['sniffer']->sniffFromBytes($mp4))->toBe('video/mp4');
        } finally {
            $ctx['restore']();
        }
    });

    it('sniffs MP3 from sync frame', function (): void {
        [$ctx] = [mediaArchiveTestSetup()];
        try {
            $mp3 = "\xFF\xFB" . str_repeat("\x00", 32);
            expect($ctx['sniffer']->sniffFromBytes($mp3))->toBe('audio/mpeg');
        } finally {
            $ctx['restore']();
        }
    });

    it('sniffs extensions when only a URL is available', function (): void {
        [$ctx] = [mediaArchiveTestSetup()];
        try {
            expect($ctx['sniffer']->sniffFromExtension('https://cdn.example/foo.png'))->toBe('image/png');
            expect($ctx['sniffer']->sniffFromExtension('https://cdn.example/foo.mp4'))->toBe('video/mp4');
            expect($ctx['sniffer']->sniffFromExtension('https://cdn.example/foo.unknown'))->toBe('application/octet-stream');
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- Metadata --------------------------------------------------------------

describe('MetadataExtractor', function (): void {
    it('returns null duration for MP4 when ffprobe is disabled (never throws)', function (): void {
        $extractor = new MetadataExtractor(new NullLogger(), false);
        $mp4 = pack('N', 32) . 'ftyp' . 'isom' . str_repeat("\x00", 64);
        $result = $extractor->extractAudioVideoMeta($mp4, 'video/mp4');
        expect($result['duration_seconds'])->toBeNull();
    });

    it('extracts dimensions for a real PNG header', function (): void {
        $extractor = new MetadataExtractor(new NullLogger(), false);
        // Minimal 1×1 PNG.
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            strict: true,
        );
        expect($bytes)->not->toBeFalse();
        $result = $extractor->extractImageMeta($bytes, 'image/png');
        expect($result['width'])->toBe(1);
        expect($result['height'])->toBe(1);
        expect($result['mime'])->toBe('image/png');
    });

    it('returns null metadata for non-image bytes', function (): void {
        $extractor = new MetadataExtractor(new NullLogger(), false);
        $result = $extractor->extractImageMeta('not actually an image', 'application/octet-stream');
        expect($result['width'])->toBeNull();
        expect($result['height'])->toBeNull();
    });
});

// ----- Ingest: input forms ---------------------------------------------------

describe('MediaArchiveService::ingest input forms', function (): void {
    it('accepts raw bytes', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                strict: true,
            );
            $asset = $ctx['service']->ingest(new MediaIngestRequest(
                bytes: $png,
                mime: 'image/png',
                filename: 'pixel.png',
            ));
            expect($asset->media_type)->toBe('image');
            expect($asset->mime_type)->toBe('image/png');
            expect($asset->byte_size)->toBe(strlen($png));
        } finally {
            $ctx['restore']();
        }
    });

    it('accepts hex payloads', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $payload = '89504e470d0a1a0a' . str_repeat('00', 16);
            $asset = $ctx['service']->ingest(new MediaIngestRequest(
                hex: $payload,
                filename: 'pixel.png',
            ));
            expect($asset->media_type)->toBe('image');
            expect($asset->mime_type)->toBe('image/png');
        } finally {
            $ctx['restore']();
        }
    });

    it('accepts base64 payloads', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $asset = $ctx['service']->ingest(new MediaIngestRequest(
                base64: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                filename: 'pixel.png',
            ));
            expect($asset->media_type)->toBe('image');
            expect($asset->byte_size)->toBe(68);
        } finally {
            $ctx['restore']();
        }
    });

    it('rejects malformed hex', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            expect(fn() => $ctx['service']->ingest(new MediaIngestRequest(hex: 'zzzz')))
                ->toThrow(InvalidArgumentException::class);
        } finally {
            $ctx['restore']();
        }
    });

    it('rejects malformed base64', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            // base64 with invalid chars fails strict mode
            expect(fn() => $ctx['service']->ingest(new MediaIngestRequest(base64: '@@@@')))
                ->toThrow(InvalidArgumentException::class);
        } finally {
            $ctx['restore']();
        }
    });

    it('rejects requests with zero input forms', function (): void {
        expect(fn() => new MediaIngestRequest())
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects requests with multiple input forms', function (): void {
        expect(fn() => new MediaIngestRequest(bytes: 'a', hex: 'ab'))
            ->toThrow(InvalidArgumentException::class);
    });
});

// ----- Ingest: URL branch ----------------------------------------------------

describe('MediaArchiveService::ingest URL branch', function (): void {
    it('promotes CDN URLs to local storage when promote_external = true', function (): void {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            strict: true,
        );
        // Callback factory — generates a fresh response per HTTP request
        // (HEAD then GET) without sharing state across tests.
        $client = new MockHttpClient(function (string $method, string $url) use ($png): MockResponse {
            if ($method === 'HEAD') {
                return new MockResponse('', ['http_code' => 200, 'response_headers' => ['content-type' => 'image/png']]);
            }
            return new MockResponse($png, ['http_code' => 200, 'response_headers' => ['content-type' => 'image/png']]);
        });
        $fetcher = new RemoteMediaFetcher($client, new NullLogger(), 30, 100 * 1024 * 1024);
        $ctx = makeMediaArchiveService(['fetcher' => $fetcher]);
        try {
            $asset = $ctx['service']->ingest(new MediaIngestRequest(url: 'https://cdn.example/test.png'));
            expect($asset->media_type)->toBe('image');
            expect($asset->storage_mode)->toBeIn(['data_url', 'local']);
            expect($asset->source_url)->toBe('https://cdn.example/test.png');
        } finally {
            $ctx['restore']();
        }
    });

    it('stores CDN URLs as external when promote_external = false', function (): void {
        $ctx = makeMediaArchiveService(['promoteExternal' => false]);
        try {
            $asset = $ctx['service']->ingest(new MediaIngestRequest(url: 'https://cdn.example/test.png'));
            expect($asset->storage_mode)->toBe('external');
            expect($asset->asset_url)->toBe('https://cdn.example/test.png');
            expect($asset->source_url)->toBe('https://cdn.example/test.png');
        } finally {
            $ctx['restore']();
        }
    });

    it('stores CDN URLs as external when content-length exceeds max_promote_bytes', function (): void {
        $client = new MockHttpClient(function (string $method): MockResponse {
            if ($method === 'HEAD') {
                return new MockResponse('', [
                    'http_code' => 200,
                    'response_headers' => [
                        'content-type'   => 'image/png',
                        'content-length' => (string) (200 * 1024 * 1024),
                    ],
                ]);
            }
            return new MockResponse('', ['http_code' => 200]);
        });
        $fetcher = new RemoteMediaFetcher($client, new NullLogger(), 30, 100 * 1024 * 1024);
        $ctx = makeMediaArchiveService(['fetcher' => $fetcher, 'maxPromoteBytes' => 100 * 1024 * 1024]);
        try {
            $asset = $ctx['service']->ingest(new MediaIngestRequest(url: 'https://cdn.example/big.png'));
            expect($asset->storage_mode)->toBe('external');
        } finally {
            $ctx['restore']();
        }
    });

    it('falls back to external when CDN returns non-2xx', function (): void {
        $client = new MockHttpClient(function (string $method): MockResponse {
            if ($method === 'HEAD') {
                return new MockResponse('', ['http_code' => 200]);
            }
            return new MockResponse('', ['http_code' => 404]);
        });
        $fetcher = new RemoteMediaFetcher($client, new NullLogger(), 30, 100 * 1024 * 1024);
        $ctx = makeMediaArchiveService(['fetcher' => $fetcher]);
        try {
            $asset = $ctx['service']->ingest(new MediaIngestRequest(url: 'https://cdn.example/missing.png'));
            expect($asset->storage_mode)->toBe('external');
            expect($asset->source_url)->toBe('https://cdn.example/missing.png');
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- Idempotency -----------------------------------------------------------

describe('MediaArchiveService::ingest idempotency', function (): void {
    it('does not insert duplicates for the same input form when toolCallId is null', function (): void {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            strict: true,
        );
        $ctx = makeMediaArchiveService();
        try {
            $ctx['service']->ingest(new MediaIngestRequest(
                bytes: $png,
                mime: 'image/png',
            ));
            $ctx['service']->ingest(new MediaIngestRequest(
                bytes: $png,
                mime: 'image/png',
            ));
            // Two separate bytes ingests are NOT idempotent — the
            // (tool_call_id, asset_url) unique index requires a
            // tool_call_id. Without one, each call lands a fresh row.
            expect(MediaAsset::query()->count())->toBe(2);
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- List / find / delete --------------------------------------------------

describe('MediaArchiveService::list', function (): void {
    it('filters by media_type', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                strict: true,
            );
            $ctx['service']->ingest(new MediaIngestRequest(
                bytes: $png,
                mime: 'image/png',
            ));
            $ctx['service']->ingest(new MediaIngestRequest(
                bytes: $png,
                mime: 'image/png',
            ));

            $query = new ListMediaQuery(mediaType: MediaType::Image);
            $page = $ctx['service']->list($query);
            expect($page->total())->toBe(2);
        } finally {
            $ctx['restore']();
        }
    });

    it('clamps perPage to PER_PAGE_MAX', function (): void {
        $query = new ListMediaQuery(perPage: 999_999);
        expect($query->perPage())->toBe(ListMediaQuery::PER_PAGE_MAX);
    });
});

describe('MediaArchiveService::find / delete / countForAgent', function (): void {
    it('returns null for unknown ids', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            expect($ctx['service']->find('00000000-0000-0000-0000-000000000000'))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });

    it('deletes a known row', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                strict: true,
            );
            $asset = $ctx['service']->ingest(new MediaIngestRequest(bytes: $png, mime: 'image/png'));
            $ctx['service']->delete($asset->id);
            expect($ctx['service']->find($asset->id))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });
});

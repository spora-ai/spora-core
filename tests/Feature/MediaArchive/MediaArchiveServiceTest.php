<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use DateTimeImmutable;
use FilesystemIterator;
use InvalidArgumentException;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\MediaAsset;
use Spora\Models\Task;
use Spora\Models\ToolCall;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Services\AssetTooLargeException;
use Spora\Services\AutoAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaArchiveException;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaIngestDecoder;
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

// makeMediaArchiveService() is autoloaded globally via composer.json
// (autoload-dev.files -> tests/Support/CrossFileTestHelpers.php). Calls
// inside this file resolve via PHP's namespace fallback to the global one.

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

    it('rejects requests whose source is an empty string', function (): void {
        // Empty strings are not a valid source — they would otherwise pass
        // the `isset()` check used by the old validation.
        expect(fn() => new MediaIngestRequest(bytes: ''))
            ->toThrow(InvalidArgumentException::class, 'exactly one non-empty source');
        expect(fn() => new MediaIngestRequest(hex: ''))
            ->toThrow(InvalidArgumentException::class, 'exactly one non-empty source');
        expect(fn() => new MediaIngestRequest(base64: ''))
            ->toThrow(InvalidArgumentException::class, 'exactly one non-empty source');
        expect(fn() => new MediaIngestRequest(url: ''))
            ->toThrow(InvalidArgumentException::class, 'exactly one non-empty source');
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
            // Asset URL is always the opaque `/api/v1/assets/<uuid>.<ext>` form
            // after fix/opaque-asset-urls — the upstream CDN URL is now
            // preserved in `source_url` for operator audit. The sniffed
            // mime (image/png from the .png extension) maps to .png so
            // browsers use the right filename on download.
            expect($asset->asset_url)->toBe('/api/v1/assets/' . $asset->id . '.png');
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
            // (tool_call_id, source_url) dedup key requires both fields,
            // and bytes inputs have no `source_url` by definition.
            // Without one, each call lands a fresh row.
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

    it('delete is a no-op for unknown ids', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $ctx['service']->delete('00000000-0000-0000-0000-000000000000');
            expect($ctx['service']->find('00000000-0000-0000-0000-000000000000'))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });

    it('countForAgent returns 0 when no rows match', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            expect($ctx['service']->countForAgent(0))->toBe(0);
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- Idempotency: tool_call_id upsert path ----------------------------------

describe('MediaArchiveService::ingest idempotency without tool_call_id', function (): void {
    it('persists the same URL twice as separate rows when neither has a tool_call_id (no dedup without the key)', function (): void {
        // promote_external=false short-circuits the URL branch before any
        // HTTP call, so the service exercises `persistExternal()` twice
        // and lands two separate external rows.
        $ctx = makeMediaArchiveService(['promoteExternal' => false]);
        try {
            $ctx['service']->ingest(new MediaIngestRequest(
                url: 'https://cdn.example/no-tool.png',
            ));
            $ctx['service']->ingest(new MediaIngestRequest(
                url: 'https://cdn.example/no-tool.png',
            ));
            // Two separate external rows — without a toolCallId the
            // (tool_call_id, source_url) key can't match, so each ingest
            // hits the insert path.
            expect(MediaAsset::query()->count())->toBe(2);
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- Idempotency: tool_call_id present ------------------------------------

describe('MediaArchiveService::ingest idempotency with tool_call_id', function (): void {
    it('returns the existing row when the same (tool_call_id, url) is re-ingested', function (): void {
        // Set up the FK chain so the tool_call_id FK on media_assets passes.
        // The idempotency short-circuit at the top of `ingest()` looks up
        // `(tool_call_id, url)` and returns the existing MediaAsset instead
        // of going through the URL branch and persisting a duplicate.
        $userId = bootAuthLayer()->register('idem@example.com', 'Password1!', 'Idem');

        $config = LLMDriverConfiguration::create([
            'user_id'          => null,
            'name'             => 'Idem Config',
            'driver_class'     => \Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'         => json_encode(['api_key' => 'test']),
            'is_global'        => true,
            'is_default'       => true,
            'context_window'   => 128000,
            'max_tokens_output' => 4096,
        ]);

        $agent = Agent::create([
            'user_id'              => $userId,
            'name'                 => 'idem-agent',
            'llm_driver_config_id' => $config->id,
            'max_steps'            => 10,
            'is_active'            => true,
        ]);
        $task = Task::create([
            'user_id'     => $userId,
            'agent_id'    => $agent->id,
            'status'      => 'RUNNING',
            'user_prompt' => 'idem test',
            'max_steps'   => 10,
        ]);
        $toolCall = ToolCall::create([
            'task_id'             => $task->id,
            'agent_id'            => $agent->id,
            'provider_call_id'    => 'call_idem',
            'tool_name'           => 'idem_tool',
            'tool_class'          => 'IdemTool',
            'tool_type'           => 'function',
            'operation'           => 'do',
            'operation_description' => 'do',
            'proposed_arguments'  => ['x' => 1],
            'status'              => 'EXECUTED',
        ]);

        $ctx = makeMediaArchiveService(['promoteExternal' => false]);
        try {
            // Seed a row whose `asset_url` matches the URL the ingest
            // service will look up. The short-circuit at the top of
            // `ingest()` is keyed on `(tool_call_id, request->url)` and
            // the persist() helper re-checks via `findExisting()`. Both
            // paths converge on `asset_url`, so planting a row with the
            // raw CDN URL as `asset_url` exercises the dedup.
            $existing = MediaAsset::create([
                'id'                       => '11111111-2222-3333-4444-555555555555',
                'asset_url'                => 'https://cdn.example/idem.png',
                'storage_mode'             => 'external',
                'mime_type'                => 'image/png',
                'media_type'               => 'image',
                'byte_size'                => 1024,
                'tool_call_id'             => $toolCall->id,
                'agent_id'                 => $agent->id,
                'task_id'                  => $task->id,
                'asset_token'              => str_repeat('a', 32),
                'migrated_from_inline_data_url' => false,
            ]);

            $second = $ctx['service']->ingest(new MediaIngestRequest(
                url: 'https://cdn.example/idem.png',
                toolCallId: $toolCall->id,
            ));

            // The short-circuit returns the pre-seeded row by id; no
            // second row is created.
            expect($second->id)->toBe($existing->id);
            expect(MediaAsset::query()->where('tool_call_id', $toolCall->id)->count())->toBe(1);
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- Decoding failure modes -------------------------------------------------

describe('MediaArchiveService::ingest decoding failure modes', function (): void {
    it('rejects hex with odd length', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            expect(fn() => $ctx['service']->ingest(new MediaIngestRequest(hex: 'abc')))
                ->toThrow(InvalidArgumentException::class, 'odd length');
        } finally {
            $ctx['restore']();
        }
    });

    it('rejects hex with non-hex characters', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            expect(fn() => $ctx['service']->ingest(new MediaIngestRequest(hex: 'zz')))
                ->toThrow(InvalidArgumentException::class, 'not valid hex');
        } finally {
            $ctx['restore']();
        }
    });

    it('rejects base64 with non-base64 characters', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            // "!!!!" is not valid base64 in strict mode.
            expect(fn() => $ctx['service']->ingest(new MediaIngestRequest(base64: '!!!!')))
                ->toThrow(InvalidArgumentException::class, 'not valid base64');
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- URL branch coverage ---------------------------------------------------

describe('MediaArchiveService::ingest URL branch — extension/head interaction', function (): void {
    it('sniffs external MIME from URL extension when extension is recognised', function (): void {
        $client = new MockHttpClient(function (string $method): MockResponse {
            if ($method === 'HEAD') {
                return new MockResponse('', ['http_code' => 200]);
            }
            return new MockResponse('', ['http_code' => 404]);
        });
        $fetcher = new RemoteMediaFetcher($client, new NullLogger(), 30, 100 * 1024 * 1024);
        $ctx = makeMediaArchiveService(['fetcher' => $fetcher]);
        try {
            $asset = $ctx['service']->ingest(new MediaIngestRequest(url: 'https://cdn.example/photo.jpg'));
            expect($asset->storage_mode)->toBe('external');
            expect($asset->mime_type)->toBe('image/jpeg');
            expect($asset->media_type)->toBe('image');
        } finally {
            $ctx['restore']();
        }
    });

    it('falls back to the HEAD probe content-type when the URL has no extension', function (): void {
        $client = new MockHttpClient(function (string $method): MockResponse {
            if ($method === 'HEAD') {
                return new MockResponse('', [
                    'http_code' => 200,
                    'response_headers' => ['content-type' => 'image/png'],
                ]);
            }
            return new MockResponse('', ['http_code' => 404]);
        });
        $fetcher = new RemoteMediaFetcher($client, new NullLogger(), 30, 100 * 1024 * 1024);
        $ctx = makeMediaArchiveService(['fetcher' => $fetcher]);
        try {
            $asset = $ctx['service']->ingest(new MediaIngestRequest(url: 'https://cdn.example/noext'));
            expect($asset->storage_mode)->toBe('external');
            expect($asset->mime_type)->toBe('image/png');
        } finally {
            $ctx['restore']();
        }
    });

    it('falls back to the caller hint when both extension and HEAD have no MIME', function (): void {
        $client = new MockHttpClient(function (string $method): MockResponse {
            if ($method === 'HEAD') {
                return new MockResponse('', ['http_code' => 200]);
            }
            return new MockResponse('', ['http_code' => 404]);
        });
        $fetcher = new RemoteMediaFetcher($client, new NullLogger(), 30, 100 * 1024 * 1024);
        $ctx = makeMediaArchiveService(['fetcher' => $fetcher]);
        try {
            $asset = $ctx['service']->ingest(new MediaIngestRequest(
                url: 'https://cdn.example/noext',
                mime: 'application/pdf',
            ));
            expect($asset->storage_mode)->toBe('external');
            expect($asset->mime_type)->toBe('application/pdf');
        } finally {
            $ctx['restore']();
        }
    });

    it('falls back to OCTET_STREAM when extension, HEAD, and caller hint are all empty', function (): void {
        $client = new MockHttpClient(function (string $method): MockResponse {
            if ($method === 'HEAD') {
                return new MockResponse('', ['http_code' => 200]);
            }
            return new MockResponse('', ['http_code' => 404]);
        });
        $fetcher = new RemoteMediaFetcher($client, new NullLogger(), 30, 100 * 1024 * 1024);
        $ctx = makeMediaArchiveService(['fetcher' => $fetcher]);
        try {
            $asset = $ctx['service']->ingest(new MediaIngestRequest(url: 'https://cdn.example/noext'));
            expect($asset->storage_mode)->toBe('external');
            expect($asset->mime_type)->toBe('application/octet-stream');
        } finally {
            $ctx['restore']();
        }
    });

    it('falls back to external when HEAD returns non-2xx and body fetch is also non-2xx', function (): void {
        $client = new MockHttpClient(function (string $method): MockResponse {
            if ($method === 'HEAD') {
                return new MockResponse('', ['http_code' => 500]);
            }
            return new MockResponse('', ['http_code' => 500]);
        });
        $fetcher = new RemoteMediaFetcher($client, new NullLogger(), 30, 100 * 1024 * 1024);
        $ctx = makeMediaArchiveService(['fetcher' => $fetcher]);
        try {
            $asset = $ctx['service']->ingest(new MediaIngestRequest(url: 'https://cdn.example/broken.png'));
            expect($asset->storage_mode)->toBe('external');
            expect($asset->asset_url)->toBe('/api/v1/assets/' . $asset->id . '.png');
            expect($asset->source_url)->toBe('https://cdn.example/broken.png');
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- Local-mode failure: AssetStore rejection --------------------------------

describe('MediaArchiveService::ingest local-mode failure surfaces MediaArchiveException', function (): void {
    it('throws MediaArchiveException when the asset store rejects the bytes', function (): void {
        // Swap in a stub AssetStore whose `store()` always throws.
        // The service catches AssetTooLargeException from inside the
        // store and rethrows it as MediaArchiveException (a dedicated
        // exception, not a generic RuntimeException).
        $rejectingStore = new class implements AssetStore {
            public function store(string $bytes, ?string $mime = null, ?string $filename = null): AssetReference
            {
                throw new AssetTooLargeException('test: oversize');
            }
        };

        $ctx = makeMediaArchiveService();
        try {
            // Swap in a rejecting AssetStore — the service must surface
            // the AssetTooLargeException as MediaArchiveException.
            $resolver = new MediaArchiveUrlResolver(
                $ctx['fetcher'],
                $ctx['sniffer'],
                $ctx['logger'],
            );
            $service = new MediaArchiveService(
                $rejectingStore,
                $resolver,
                $ctx['sniffer'],
                $ctx['metadata'],
                \Tests\Support\MediaArchiveTestSupport::buildConverterRegistry(),
                new MediaIngestDecoder(),
            );
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                strict: true,
            );
            expect(fn() => $service->ingest(new MediaIngestRequest(bytes: $png, mime: 'image/png')))
                ->toThrow(MediaArchiveException::class);
        } finally {
            $ctx['restore']();
        }
    });

    it('MediaArchiveException is a RuntimeException (still catchable by callers)', function (): void {
        expect(new MediaArchiveException('boom'))->toBeInstanceOf(RuntimeException::class);
    });
});

// ----- List filter branches --------------------------------------------------

describe('MediaArchiveService::list', function (): void {
    it('returns an empty page when no rows exist', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $query = new ListMediaQuery();
            $page = $ctx['service']->list($query);
            expect($page->total())->toBe(0);
            expect($page->items())->toBe([]);
        } finally {
            $ctx['restore']();
        }
    });

    it('filters by plugin_slug, tool_name, and search', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                strict: true,
            );
            $ctx['service']->ingest(new MediaIngestRequest(
                bytes: $png,
                mime: 'image/png',
                pluginSlug: 'foo',
                toolName: 'tavily',
                prompt: 'hello world',
            ));
            $ctx['service']->ingest(new MediaIngestRequest(
                bytes: $png,
                mime: 'image/png',
                pluginSlug: 'bar',
                toolName: 'serper',
                prompt: 'goodbye world',
            ));

            expect($ctx['service']->list(new ListMediaQuery(pluginSlug: 'foo'))->total())->toBe(1);
            expect($ctx['service']->list(new ListMediaQuery(toolName: 'serper'))->total())->toBe(1);
            expect($ctx['service']->list(new ListMediaQuery(search: 'hello'))->total())->toBe(1);
            expect($ctx['service']->list(new ListMediaQuery(search: '  hello  '))->total())->toBe(1);
        } finally {
            $ctx['restore']();
        }
    });

    it('ignores a whitespace-only search term', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                strict: true,
            );
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $png, mime: 'image/png'));
            // Whitespace-only search must not throw and must match all rows.
            expect($ctx['service']->list(new ListMediaQuery(search: '   '))->total())->toBe(1);
        } finally {
            $ctx['restore']();
        }
    });

    it('applies the from/to date filters', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                strict: true,
            );
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $png, mime: 'image/png'));

            $future = new DateTimeImmutable('+1 day');
            $past   = new DateTimeImmutable('-1 day');

            expect($ctx['service']->list(new ListMediaQuery(from: $future))->total())->toBe(0);
            expect($ctx['service']->list(new ListMediaQuery(to: $past))->total())->toBe(0);
            expect($ctx['service']->list(new ListMediaQuery(from: $past, to: $future))->total())->toBe(1);
        } finally {
            $ctx['restore']();
        }
    });

    it('falls back to SORT_CREATED_DESC for an unrecognised sort key', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                strict: true,
            );
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $png, mime: 'image/png'));
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $png, mime: 'image/png'));

            $page = $ctx['service']->list(new ListMediaQuery(sort: 'bogus'));
            expect($page->total())->toBe(2);
        } finally {
            $ctx['restore']();
        }
    });

    it('honours SORT_CREATED_ASC and SORT_SIZE_DESC', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                strict: true,
            );
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $png, mime: 'image/png'));

            $asc = $ctx['service']->list(new ListMediaQuery(sort: ListMediaQuery::SORT_CREATED_ASC));
            $size = $ctx['service']->list(new ListMediaQuery(sort: ListMediaQuery::SORT_SIZE_DESC));
            expect($asc->total())->toBe(1);
            expect($size->total())->toBe(1);
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- Idempotency on (tool_call_id, asset_url) -----------------------------

describe('MediaArchiveService::ingest idempotency on the URL branch', function (): void {
    it('upserts when both ingest calls share the same URL (URL branch, no toolCallId FK constraint)', function (): void {
        // toolCallId is omitted (it's a FK to the tasks table — without a
        // real task row, the FK constraint would fire). The service's
        // `findExisting()` short-circuits when toolCallId is null, so two
        // external ingests of the same URL still produce two rows — this
        // pins the documented "no dedup without a key" behaviour.
        $ctx = makeMediaArchiveService(['promoteExternal' => false]);
        try {
            $ctx['service']->ingest(new MediaIngestRequest(
                url: 'https://cdn.example/same.png',
            ));
            $ctx['service']->ingest(new MediaIngestRequest(
                url: 'https://cdn.example/same.png',
            ));
            // Without a toolCallId, each ingest is fresh — two rows.
            expect(MediaAsset::query()->count())->toBe(2);
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- URL branch: missing Content-Length -----------------------------------

describe('MediaArchiveService::ingest URL branch — Content-Length absent', function (): void {
    it('promotes a URL response without a Content-Length header (size unknown → local)', function (): void {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            strict: true,
        );
        $client = new MockHttpClient(function (string $method) use ($png): MockResponse {
            if ($method === 'HEAD') {
                // No content-length header — server doesn't know / doesn't
                // send it. HEAD still succeeds.
                return new MockResponse('', [
                    'http_code' => 200,
                    'response_headers' => ['content-type' => 'image/png'],
                ]);
            }
            return new MockResponse($png, [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'image/png'],
            ]);
        });
        $fetcher = new RemoteMediaFetcher($client, new NullLogger(), 30, 100 * 1024 * 1024);
        $ctx = makeMediaArchiveService(['fetcher' => $fetcher]);
        try {
            $asset = $ctx['service']->ingest(new MediaIngestRequest(url: 'https://cdn.example/sizeless.png'));
            // No content-length means the headSaysTooLarge() guard can't fire,
            // so the body is fetched and the asset is promoted to local/data_url.
            expect($asset->storage_mode)->toBeIn(['data_url', 'local']);
            expect($asset->media_type)->toBe('image');
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- Bytes branch: idempotency on (tool_call_id, source_url) ---------------

describe('MediaArchiveService::ingest bytes branch — repeated bytes without toolCallId', function (): void {
    it('persists two separate rows when the same bytes are ingested without a toolCallId', function (): void {
        // FK constraint on tool_call_id → omitting the field avoids the
        // need for a real task row. The service's `findExisting()` requires
        // a toolCallId to dedup, so each call lands a fresh row.
        $ctx = makeMediaArchiveService();
        try {
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                strict: true,
            );

            $ctx['service']->ingest(new MediaIngestRequest(bytes: $png, mime: 'image/png'));
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $png, mime: 'image/png'));

            expect(MediaAsset::query()->count())->toBe(2);
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- Dedup by (tool_call_id, source_url) post refactor --------------------

describe('MediaArchiveService::ingest idempotency by source_url', function (): void {
    it('returns the existing row when the same URL is re-ingested against the same toolCallId (matches by source_url)', function (): void {
        // After the opaque-URL refactor, the dedup key is
        // `(tool_call_id, source_url)` (migration 0054). The seeded row
        // carries the rewritten `/api/v1/assets/<uuid>` form in
        // `asset_url` but the upstream CDN URL in `source_url`. A
        // re-ingest of the same upstream URL with the same toolCallId
        // must still short-circuit to the existing row.
        $userId = bootAuthLayer()->register('idem2@example.com', 'Password1!', 'Idem2');

        $config = LLMDriverConfiguration::create([
            'user_id'          => null,
            'name'             => 'Idem2 Config',
            'driver_class'     => \Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'         => json_encode(['api_key' => 'test']),
            'is_global'        => true,
            'is_default'       => true,
            'context_window'   => 128000,
            'max_tokens_output' => 4096,
        ]);

        $agent = Agent::create([
            'user_id'              => $userId,
            'name'                 => 'idem2-agent',
            'llm_driver_config_id' => $config->id,
            'max_steps'            => 10,
            'is_active'            => true,
        ]);
        $task = Task::create([
            'user_id'     => $userId,
            'agent_id'    => $agent->id,
            'status'      => 'RUNNING',
            'user_prompt' => 'idem2 test',
            'max_steps'   => 10,
        ]);
        $toolCall = ToolCall::create([
            'task_id'             => $task->id,
            'agent_id'            => $agent->id,
            'provider_call_id'    => 'call_idem2',
            'tool_name'           => 'idem2_tool',
            'tool_class'          => 'Idem2Tool',
            'tool_type'           => 'function',
            'operation'           => 'do',
            'operation_description' => 'do',
            'proposed_arguments'  => ['x' => 1],
            'status'              => 'EXECUTED',
        ]);

        $ctx = makeMediaArchiveService(['promoteExternal' => false]);
        try {
            // Seed a row in the post-refactor shape: source_url is the
            // upstream CDN URL the operator asked us to archive, asset_url
            // is the rewritten opaque form.
            $existing = MediaAsset::create([
                'id'                       => '22222222-3333-4444-5555-666666666666',
                'asset_url'                => '/api/v1/assets/22222222-3333-4444-5555-666666666666.png',
                'source_url'               => 'https://cdn.example/idem2.png',
                'storage_mode'             => 'external',
                'mime_type'                => 'image/png',
                'media_type'               => 'image',
                'byte_size'                => 1024,
                'tool_call_id'             => $toolCall->id,
                'agent_id'                 => $agent->id,
                'task_id'                  => $task->id,
                'asset_token'              => str_repeat('b', 32),
                'migrated_from_inline_data_url' => false,
            ]);

            $second = $ctx['service']->ingest(new MediaIngestRequest(
                url: 'https://cdn.example/idem2.png',
                toolCallId: $toolCall->id,
            ));

            // The short-circuit must hit on `source_url` (the new dedup
            // key), even though `asset_url` no longer matches the URL
            // the caller passed.
            expect($second->id)->toBe($existing->id);
            expect(MediaAsset::query()->where('tool_call_id', $toolCall->id)->count())->toBe(1);
        } finally {
            $ctx['restore']();
        }
    });
});

// ----- AssetStore behaviour on the local-mode bytes branch -----------------

describe('MediaArchiveService::ingest bytes branch — asset_url carries the store result', function (): void {
    it('records the AssetReference url and mode on the persisted row', function (): void {
        $ctx = makeMediaArchiveService();
        try {
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                strict: true,
            );

            $asset = $ctx['service']->ingest(new MediaIngestRequest(bytes: $png, mime: 'image/png'));

            // asset_url is the canonical URL the AssetReference returned
            // (data: URL for small payloads in this configuration).
            expect($asset->asset_url)->not->toBe('');
            expect($asset->storage_mode)->toBeIn(['data_url', 'local']);
            expect($asset->byte_size)->toBe(strlen($png));
        } finally {
            $ctx['restore']();
        }
    });
});

<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use LogicException;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\SpeechTranscribeController;
use Spora\Models\MediaAsset;
use Spora\Models\Principal;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Speech\InvalidAudioException;
use Spora\Speech\SpeechToTextException;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Speech\TranscriptionResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\MediaArchiveTestSupport;
use Throwable;

/**
 * Controller tests for the speech transcribe endpoint.
 *
 * Uses the real `MediaArchiveService` + `MediaAssetReader` against the
 * test DB. The provider is an anonymous-class stub — we want to pin
 * what the controller hands the provider and how it maps the result /
 * exception to the wire shape, without standing up a real STT service.
 */

final class TransStubConfigured implements SpeechToTextProviderInterface
{
    /** @var list<array{bytes: string, mime: string, lang: ?string}> */
    public array $calls = [];
    public ?TranscriptionResult $result = null;
    public ?Throwable $throws = null;

    public function getName(): string
    {
        return 'stub-configured';
    }
    public function getDisplayName(): string
    {
        return 'Stub Configured';
    }
    public function isConfigured(): bool
    {
        return true;
    }

    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult {
        $this->calls[] = ['bytes' => $bytes, 'mime' => $mimeType, 'lang' => $languageHint];
        if ($this->throws !== null) {
            throw $this->throws;
        }
        return $this->result ?? new TranscriptionResult('hello world', 'en', 1234.0);
    }
}

final class TransStubUnconfigured implements SpeechToTextProviderInterface
{
    public function getName(): string
    {
        return 'stub-unconfigured';
    }
    public function getDisplayName(): string
    {
        return 'Stub Unconfigured';
    }
    public function isConfigured(): bool
    {
        return false;
    }
    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult {
        throw new LogicException('configuredProvider() should never pick me');
    }
}

/**
 * Build the controller graph with the real service + reader. Returns a
 * 4-tuple: [$controller, $service, $userId, $assetId].
 *
 * @return array{0: SpeechTranscribeController, 1: MediaArchiveService, 2: int, 3: string}
 */
function buildTransFixtures(SpeechToTextProviderInterface $provider): array
{
    $tmp = sys_get_temp_dir() . '/spora-trans-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $auth = bootAuthLayer();
    $userId = bootAuth($auth, 'transcribe@example.com', 'Password1!', 'Transcriber');
    $userPrincipalId = (int) Principal::where('type', Principal::TYPE_USER)->where('user_id', $userId)->value('id');
    if ($userPrincipalId <= 0) {
        $userPrincipalId = (new PrincipalService(new PrincipalResolver()))->ensureUserPrincipal($userId)->id;
    }

    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);
    $service = MediaArchiveTestSupport::buildService($assetStore);
    $reader  = new MediaAssetReader($database, $local);

    $controller = new SpeechTranscribeController(
        registry: new SpeechToTextRegistry([$provider]),
        mediaReader: $reader,
        mediaArchive: $service,
        auth: $auth,
    );

    $asset = $service->ingest(new MediaIngestRequest(
        // WebM EBML magic (\x1A\x45\xDF\xA3) + padding so the MIME sniffer
        // keeps it as audio/webm end-to-end. RIFF…WAVE would be sniffed
        // back to audio/wav.
        bytes: "\x1A\x45\xDF\xA3\x02\x00\x00\x00\x00\x00\x00\x1F",
        mime: 'audio/webm',
        agentId: null,
        userId: $userId,
        pluginSlug: null,
        toolName: null,
        prompt: null,
        filename: 'recording.webm',
        mediaType: MediaType::Audio,
    ));

    return [$controller, $service, $userId, $asset->id];
}

function jsonTransRequest(array $body): Request
{
    return Request::create(
        '/api/v1/speech/transcribe',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($body),
    );
}

test('returns 401 when not logged in', function (): void {
    $provider = new TransStubConfigured();
    [$controller] = buildTransFixtures($provider);
    clearSession();

    $resp = $controller->transcribe(jsonTransRequest(['media_id' => '00000000-0000-4000-8000-000000000000']));

    expect($resp->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('UNAUTHORIZED');
});

test('returns 422 when body is not valid JSON', function (): void {
    $provider = new TransStubConfigured();
    [$controller] = buildTransFixtures($provider);

    $resp = $controller->transcribe(Request::create(
        '/api/v1/speech/transcribe',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{ not json',
    ));

    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');
});

test('returns 422 when media_id is missing', function (): void {
    $provider = new TransStubConfigured();
    [$controller] = buildTransFixtures($provider);

    $resp = $controller->transcribe(jsonTransRequest([]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');
});

test('returns 422 when language is not a string', function (): void {
    $provider = new TransStubConfigured();
    [$controller] = buildTransFixtures($provider);

    $resp = $controller->transcribe(jsonTransRequest([
        'media_id' => '00000000-0000-4000-8000-000000000000',
        'language' => 42,
    ]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');
});

test('returns 503 when no provider is configured', function (): void {
    [$controller] = buildTransFixtures(new TransStubUnconfigured());

    $resp = $controller->transcribe(jsonTransRequest([
        'media_id' => '00000000-0000-4000-8000-000000000000',
    ]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('SPEECH_PROVIDER_UNAVAILABLE');
});

test('returns 404 when the asset does not exist or is not owned by the caller', function (): void {
    $provider = new TransStubConfigured();
    [$controller] = buildTransFixtures($provider);

    $resp = $controller->transcribe(jsonTransRequest([
        'media_id' => '00000000-0000-4000-8000-000000000000',
    ]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('MEDIA_NOT_FOUND');

    // Provider must never be invoked when the reader returned null.
    expect($provider->calls)->toBe([]);
});

test('happy path: 200 with text/language/duration_ms; transcript cached on the asset', function (): void {
    $provider = new TransStubConfigured();
    [$controller, , , $assetId] = buildTransFixtures($provider);

    $resp = $controller->transcribe(jsonTransRequest([
        'media_id' => $assetId,
        'language' => 'en-US',
    ]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    // JSON round-trip: json_encode drops the trailing .0 on integer-valued
    // floats (PHP's default behaviour — JSON_PRESERVE_ZERO_FRACTION is off).
    // The semantic value is identical; assert loosely.
    expect($body['data']['text'])->toBe('hello world')
        ->and($body['data']['language'])->toBe('en')
        ->and((float) $body['data']['duration_ms'])->toBe(1234.0);

    // Provider received the right bytes + mime + language hint. The mime
    // is the SNIFFED value (`MediaArchiveIngestPipeline` persists the sniffer
    // output, not the user-declared mime). For WebM/EBML the sniffer
    // can't distinguish audio vs video — both share the EBML magic — so
    // `video/webm` is what reaches the provider. The provider handles
    // either; an `audio/webm`-sniffer rule is a backlog item.
    expect($provider->calls)->toHaveCount(1);
    expect($provider->calls[0]['mime'])->toBe('video/webm');
    expect($provider->calls[0]['lang'])->toBe('en-US');

    // Transcript cached on the asset row.
    $asset = MediaAsset::query()->find($assetId);
    expect($asset->transcript)->toBe('hello world')
        ->and($asset->transcript_language)->toBe('en');
});

test('language hint null → provider receives null', function (): void {
    $provider = new TransStubConfigured();
    [$controller, , , $assetId] = buildTransFixtures($provider);

    $resp = $controller->transcribe(jsonTransRequest(['media_id' => $assetId]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    expect($provider->calls[0]['lang'])->toBeNull();
});

test('returns 422 on InvalidAudioException from the provider', function (): void {
    $provider = new TransStubConfigured();
    $provider->throws = new InvalidAudioException('cannot ingest audio/webm');
    [$controller, , , $assetId] = buildTransFixtures($provider);

    $resp = $controller->transcribe(jsonTransRequest(['media_id' => $assetId]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_AUDIO')
        ->and($body['error']['message'])->toBe('cannot ingest audio/webm');
});

test('returns 502 on SpeechToTextException from the provider', function (): void {
    $provider = new TransStubConfigured();
    $provider->throws = new SpeechToTextException('upstream 502 from provider');
    [$controller, , , $assetId] = buildTransFixtures($provider);

    $resp = $controller->transcribe(jsonTransRequest(['media_id' => $assetId]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_BAD_GATEWAY);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('SPEECH_PROVIDER_FAILED');
});

test('writeTranscript is not called when the provider throws', function (): void {
    $provider = new TransStubConfigured();
    $provider->throws = new SpeechToTextException('upstream 502');
    [$controller, , , $assetId] = buildTransFixtures($provider);

    $controller->transcribe(jsonTransRequest(['media_id' => $assetId]));

    // The asset's transcript column must remain null — the controller
    // shouldn't cache a failed call.
    $asset = MediaAsset::query()->find($assetId);
    expect($asset->transcript)->toBeNull();
});

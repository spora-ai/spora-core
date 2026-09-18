<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use LogicException;
use Mockery;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\SpeechTranscribeController;
use Spora\Models\MediaAsset;
use Spora\Models\Principal;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\AgentPrincipalService;
use Spora\Services\AgentService;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigService;
use Spora\Services\ToolIconResolver;
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
    /** @var list<array{bytes: string, mime: string, lang: ?string, agent: ?int, user: int}> */
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
    public function bindLabel(string $label): void
    {
        // no-op for the stub — tests don't exercise label binding
    }
    public function bindSettings(array $settings): void
    {
        // no-op for the stub — tests don't exercise settings binding
    }

    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult {
        $this->calls[] = [
            'bytes' => $bytes,
            'mime'  => $mimeType,
            'lang'  => $languageHint,
            'agent' => $agentId,
            'user'  => (int) $userId,
        ];
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
    public function bindLabel(string $label): void
    {
        // no-op for the stub — tests don't exercise label binding
    }
    public function bindSettings(array $settings): void
    {
        // no-op for the stub — tests don't exercise settings binding
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

    $config = Mockery::mock(ToolConfigService::class);
    // Mock is unused since the registry's new FK-driven cascade no
    // longer calls ToolConfigService; keep the variable around in
    // case future SpeechTranscribe tests need to stub settings.
    $config->shouldIgnoreMissing();

    $pluginLoader = new \Spora\Plugins\PluginLoader([], null);
    $agentService = new AgentService(
        new ToolIconResolver(
            new \Spora\Services\ToolConfigNameResolver(new \Psr\Log\NullLogger(), []),
            $pluginLoader,
        ),
        new AgentPictureService(),
        new PrincipalResolver(),
        new AgentPrincipalService(new PrincipalService(new PrincipalResolver())),
    );

    $controller = new SpeechTranscribeController(
        registry: new SpeechToTextRegistry([$provider], new PrincipalService(new PrincipalResolver())),
        mediaReader: $reader,
        mediaArchive: $service,
        auth: $auth,
        agentService: $agentService,
    );

    // Set up the v2-cascade global default so the cascade's tier 3
    // resolves to the configured provider. With tier 5's silent
    // fallback removed, configuredProvider() short-circuits without a
    // `speech_provider_configurations` row, so the fixture writes one
    // before each test. The settings blob is `{}` — the stubs ignore
    // settings (the v2 cascade decodes it via
    // `SpeechProviderConfigPersistence::decodeSettings`, but
    // `TransStubConfigured::bindSettings()` is a no-op).
    \Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insert([
        'provider_class' => $provider::class,
        'display_name'   => 'Test Global Default',
        'principal_id'   => null,
        'settings'       => '{}',
        'is_default'     => true,
        'is_global'      => true,
        'created_at'     => date('Y-m-d H:i:s'),
        'updated_at'     => date('Y-m-d H:i:s'),
    ]);

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

test('returns 422 INVALID_AUDIO for externally-stored media and never invokes the provider', function (): void {
    // Regression: `MediaAssetReader::readAsset()` returns
    // `['status' => 'external', 'sourceUrl' => '…']` for rows whose
    // `storage_mode = 'external'`. The provider can't consume a URL —
    // the controller must short-circuit with 422 and skip `transcribe()`.
    $provider = new TransStubConfigured();
    [$controller, $service, $userId] = buildTransFixtures($provider);

    $row = new MediaAsset();
    $row->id           = '00000000-0000-4000-8000-000000000abc';
    $row->user_id      = $userId;
    $row->media_type   = MediaType::Audio->value;
    $row->mime_type    = 'audio/mp3';
    $row->storage_mode = 'external';
    $row->source_url   = 'https://example.invalid/recording.mp3';
    $row->asset_url    = 'https://example.invalid/recording.mp3';
    $row->save();

    $resp = $controller->transcribe(jsonTransRequest(['media_id' => $row->id]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('INVALID_AUDIO');

    // Provider must never be invoked — the asset had no bytes to forward.
    expect($provider->calls)->toBe([]);

    // And the cached transcript must remain unset (no provider call,
    // no writeTranscript).
    expect(MediaAsset::query()->find($row->id)->transcript)->toBeNull();

    // Touch the unused destructors so static analysers don't flag them.
    unset($service);
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

test('returns 422 when agent_id is not an integer', function (): void {
    $provider = new TransStubConfigured();
    [$controller] = buildTransFixtures($provider);

    $resp = $controller->transcribe(jsonTransRequest([
        'media_id' => '00000000-0000-4000-8000-000000000000',
        'agent_id' => 'not-an-int',
    ]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');
});

test('returns 422 when agent_id does not belong to the caller', function (): void {
    $provider = new TransStubConfigured();
    [$controller, , $userId, $assetId] = buildTransFixtures($provider);

    // Try to attribute the transcription to an agent that the caller
    // does NOT own. The controller surfaces 422 VALIDATION_ERROR so a
    // probing caller cannot distinguish "doesn't exist" from
    // "not yours".
    $resp = $controller->transcribe(jsonTransRequest([
        'media_id' => $assetId,
        'agent_id' => 999_999,
    ]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');
    // Provider must not be invoked when the agent_id is rejected.
    expect($provider->calls)->toBe([]);
});

test('agent_id in request body is validated for ownership but never threaded into the provider (cascade ignores agent overrides)', function (): void {
    $provider = new TransStubConfigured();
    [$controller, , $userId, $assetId] = buildTransFixtures($provider);

    // Insert an agent the caller owns so the ownership check passes.
    $principalId = createUserPrincipalPublic($userId);
    $agentId = (int) \Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
        'name'         => 'Owned Agent',
        'principal_id' => $principalId,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    $resp = $controller->transcribe(jsonTransRequest([
        'media_id' => $assetId,
        'agent_id' => $agentId,
    ]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    expect($provider->calls)->toHaveCount(1);
    // The speech cascade never reads agent_tool_overrides, so the
    // controller always passes 0 for $agentId — the provider's settings
    // lookup walks user / group / global only.
    expect($provider->calls[0]['agent'])->toBe(0);
});

test('agent_id 0 / absent: same outcome as a valid agent_id (cascade is unchanged)', function (): void {
    $provider = new TransStubConfigured();
    [$controller, , , $assetId] = buildTransFixtures($provider);

    $resp = $controller->transcribe(jsonTransRequest([
        'media_id' => $assetId,
        'agent_id' => 0,
    ]));

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    expect($provider->calls[0]['agent'])->toBe(0);
});

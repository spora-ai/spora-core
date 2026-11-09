<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\Exceptions\SpeechTranscribeException;
use Spora\Models\MediaAsset;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Speech\SpeechToTextException;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Speech\SpeechTranscriber;
use Spora\Speech\TranscriptionResult;
use Tests\Support\MediaArchiveTestSupport;

/**
 * The transcriber's contract, independent of the surface that calls it.
 *
 * Two surfaces drive it — the SPA's recorder button and the API-key
 * endpoints — and they reach an asset by different rules. What has to
 * stay true for both is which provider answers, that the provider's
 * settings lookup never sees the agent id, and that a failed call leaves
 * nothing cached behind it.
 */
final class UnitStubSpeechProvider implements SpeechToTextProviderInterface
{
    /** @var list<array{mime: string, lang: ?string, agent: ?int, user: ?int}> */
    public array $calls = [];

    public string $text = 'the transcript';

    public ?Throwable $throws = null;

    public function getName(): string
    {
        return 'unit-stub-speech';
    }

    public function getDisplayName(): string
    {
        return 'Unit Stub Speech';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function bindLabel(string $label): void {}

    public function bindSettings(array $settings): void {}

    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult {
        $this->calls[] = [
            'mime'  => $mimeType,
            'lang'  => $languageHint,
            'agent' => $agentId,
            'user'  => $userId,
        ];

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return new TranscriptionResult($this->text, 'en', 42.0);
    }
}

/**
 * @return array{0: SpeechTranscriber, 1: MediaArchiveService, 2: UnitStubSpeechProvider}
 */
function buildTranscriber(): array
{
    $tmp = sys_get_temp_dir() . '/spora-transcriber-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $service  = MediaArchiveTestSupport::buildService(new AutoAssetStore($database, $local, 1_048_576));

    $provider = new UnitStubSpeechProvider();
    $registry = new SpeechToTextRegistry([$provider], new PrincipalService(new PrincipalResolver()));

    Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insert([
        'provider_class' => UnitStubSpeechProvider::class,
        'display_name'   => 'Unit Default',
        'principal_id'   => null,
        'settings'       => '{}',
        'is_default'     => true,
        'is_global'      => true,
        'created_at'     => date('Y-m-d H:i:s'),
        'updated_at'     => date('Y-m-d H:i:s'),
    ]);

    return [
        new SpeechTranscriber(
            registry: $registry,
            mediaReader: new MediaAssetReader($database, $local),
            mediaArchive: $service,
        ),
        $service,
        $provider,
    ];
}

function ingestRecording(MediaArchiveService $service, ?int $userId): string
{
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: "\x1A\x45\xDF\xA3\x02\x00\x00\x00\x00\x00\x00\x1F",
        mime: 'audio/webm',
        userId: $userId,
        filename: 'recording.webm',
        mediaType: MediaType::Audio,
    ));

    return (string) $asset->id;
}

test('transcribeOwned refuses an asset the caller does not own', function (): void {
    [$transcriber, $service] = buildTranscriber();

    $assetId = ingestRecording($service, null);

    expect(fn() => $transcriber->transcribeOwned($assetId, 0, 4242))
        ->toThrow(SpeechTranscribeException::class);
});

test('transcribeReachable reads an asset the caller reached by its own rule', function (): void {
    [$transcriber, $service, $provider] = buildTranscriber();

    // A plugin stores an asset for the agent without a user on the row, so
    // the reader's user-based predicate cannot see it. The API-key surfaces
    // reach it through the key's own scope and say so by skipping that
    // narrower check.
    $assetId = ingestRecording($service, null);

    $result = $transcriber->transcribeReachable($assetId, 0, 4242);

    expect($result->text)->toBe('the transcript');
    expect($provider->calls)->toHaveCount(1);
});

test('the provider never sees the agent id', function (): void {
    [$transcriber, $service, $provider] = buildTranscriber();

    $assetId = ingestRecording($service, 7);

    $transcriber->transcribeOwned($assetId, 0, 7);

    // The speech cascade deliberately ignores agent_tool_overrides: the v2
    // configuration row the registry bound is the source of truth, and a
    // per-agent tool override must not be able to swap the API key.
    expect($provider->calls[0]['agent'])->toBe(0);
    expect($provider->calls[0]['user'])->toBe(7);
});

test('the transcript is cached on the asset', function (): void {
    [$transcriber, $service] = buildTranscriber();

    $assetId = ingestRecording($service, 7);

    $transcriber->transcribeOwned($assetId, 0, 7);

    expect(MediaAsset::query()->find($assetId)->transcript)->toBe('the transcript');
});

test('a failed call leaves nothing cached behind it', function (): void {
    [$transcriber, $service, $provider] = buildTranscriber();

    $provider->throws = new SpeechToTextException('upstream is down');

    $assetId = ingestRecording($service, 7);

    try {
        $transcriber->transcribeOwned($assetId, 0, 7);
        $this->fail('The provider failure should have surfaced.');
    } catch (SpeechTranscribeException $e) {
        expect($e->statusCode)->toBe(502);
        expect($e->errorCode)->toBe('SPEECH_PROVIDER_FAILED');
    }

    expect(MediaAsset::query()->find($assetId)->transcript)->toBeNull();
});

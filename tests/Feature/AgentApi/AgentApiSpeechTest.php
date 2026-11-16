<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Http\AgentApi\AgentApiSpeechController;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\OpenApi\RouteToOpenApi;
use Spora\Services\ApiKeys\ApiKeyFileIngest;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Speech\TranscriptionResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A speech provider that answers with a canned transcript.
 *
 * The calls are recorded because what a caller asserts about is not
 * "a transcript came back" but "the provider that answered was the one
 * the agent's cascade resolved".
 */
class StubSpeechProvider implements SpeechToTextProviderInterface
{
    /** @var list<array{bytes: string, mime: string, lang: ?string, agent: ?int, user: ?int}> */
    public array $calls = [];

    public string $text = 'hello from the recording';

    public ?Throwable $throws = null;

    public function getName(): string
    {
        return 'stub-speech';
    }

    public function getDisplayName(): string
    {
        return 'Stub Speech';
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
            'bytes' => $bytes,
            'mime'  => $mimeType,
            'lang'  => $languageHint,
            'agent' => $agentId,
            'user'  => $userId,
        ];

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return new TranscriptionResult($this->text, 'en', 1234.0);
    }
}

/**
 * A second provider, so "which driver answered" is a question with more
 * than one possible answer.
 */
final class StubOtherSpeechProvider extends StubSpeechProvider
{
    public function getName(): string
    {
        return 'stub-other-speech';
    }

    public function getDisplayName(): string
    {
        return 'Stub Other Speech';
    }
}

/**
 * Voice input over the Agent API.
 *
 * The surface an external tool uses to turn a recording into a message:
 * transcribe it, or transcribe it and hand it to the agent. What matters
 * here is that the driver is the agent's own, that the key's reach still
 * governs which assets may be read, and that a recording which produced
 * no words never becomes a chat.
 */
describe('Agent API speech', function (): void {
    /**
     * The container with a stub speech provider in place of the real
     * one, so no test reaches a vendor.
     *
     * @param list<SpeechToTextProviderInterface> $providers
     */
    function speechContainer(array $providers): \Psr\Container\ContainerInterface
    {
        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'ok', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 's'));

        $container = AgentApiTestSupport::container($llm);

        $container->set(SpeechToTextRegistry::class, new SpeechToTextRegistry(
            $providers,
            new PrincipalService(new PrincipalResolver()),
        ));

        return $container;
    }

    /**
     * A configuration row the cascade can resolve, so the provider is
     * the one the agent would use in production.
     *
     * @param class-string<SpeechToTextProviderInterface> $providerClass
     */
    function registerDriver(string $providerClass, bool $isDefault = true): int
    {
        return (int) Capsule::table('speech_provider_configurations')->insertGetId([
            'provider_class' => $providerClass,
            'display_name'   => 'Test Driver',
            'principal_id'   => null,
            'settings'       => '{}',
            'is_default'     => $isDefault,
            'is_global'      => true,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The bytes the sniffer names as a WebM container. Short enough to
     * keep the fixture readable, real enough for the sniffer.
     */
    function recordingBytes(): string
    {
        return "\x1A\x45\xDF\xA3\x02\x00\x00\x00\x00\x00\x00\x1F";
    }

    /**
     * An audio asset the key's agent owns.
     */
    function ingestRecording(\Psr\Container\ContainerInterface $container, array $seed): string
    {
        $mediaId = $container->get(ApiKeyFileIngest::class)->ingestBytes(
            Agent::findOrFail($seed['agentId']),
            $seed['userId'],
            recordingBytes(),
            'recording.webm',
        );

        expect($mediaId)->not->toBeNull();

        return (string) $mediaId;
    }

    /**
     * @param array<string, mixed> $body
     */
    function speechRequest(string $path, string $key, array $body = []): Request
    {
        return jsonRequest('POST', $path, $body, ['Authorization' => 'Bearer ' . $key]);
    }

    /**
     * The `effort` property the transcribe-and-send endpoint documents.
     *
     * @return array<string, mixed>|null null when the endpoint does not offer the field
     */
    function transcribeAndSendEffortProperty(): ?array
    {
        $spec = json_decode((string) json_encode((new RouteToOpenApi())->build()), true);

        $property = $spec['paths']['/api/v1/agent-api/transcribe-and-send']['post']['requestBody']['content']['application/json']['schema']['properties']['effort'] ?? null;

        return is_array($property) ? $property : null;
    }

    it('transcribes an asset and caches the transcript on it', function (): void {
        $seed = AgentApiTestSupport::seedAgent('transcribe');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'transcribe');

        $provider = new StubSpeechProvider();
        $container = speechContainer([$provider]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribe',
            speechRequest('/api/v1/agent-api/transcribe', $key, ['media_id' => $mediaId]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);

        $data = AgentApiTestSupport::decode($response)['data'];
        expect($data['media_id'])->toBe($mediaId)
            ->and($data['text'])->toBe('hello from the recording')
            ->and($data['language'])->toBe('en')
            ->and((float) $data['duration_ms'])->toBe(1234.0);

        // The provider saw the bytes, and the agent id was not threaded
        // into its settings lookup.
        expect($provider->calls)->toHaveCount(1);
        expect($provider->calls[0]['agent'])->toBe(0);

        // The transcript is cached on the asset so a re-render does not
        // pay the provider again.
        expect(MediaAsset::query()->find($mediaId)->transcript)->toBe('hello from the recording');
    });

    it('uses the driver the agent pinned over the global default', function (): void {
        $seed = AgentApiTestSupport::seedAgent('pinned');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'pinned');

        $global = new StubSpeechProvider();
        $pinned = new StubOtherSpeechProvider();
        $container = speechContainer([$global, $pinned]);

        registerDriver(StubSpeechProvider::class);
        $pinnedConfigId = registerDriver(StubOtherSpeechProvider::class, false);

        Agent::query()->where('id', $seed['agentId'])->update([
            'speech_driver_config_id' => $pinnedConfigId,
        ]);

        $mediaId = ingestRecording($container, $seed);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribe',
            speechRequest('/api/v1/agent-api/transcribe', $key, ['media_id' => $mediaId]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        expect($pinned->calls)->toHaveCount(1);
        expect($global->calls)->toBe([]);
    });

    it('refuses an asset outside the key\'s reach without calling the provider', function (): void {
        $mine = AgentApiTestSupport::seedAgent('reach-mine');
        $theirs = AgentApiTestSupport::seedAgent('reach-theirs');
        [, $key] = AgentApiTestSupport::mintKey($mine['agentId'], 'reach');

        $provider = new StubSpeechProvider();
        $container = speechContainer([$provider]);
        registerDriver(StubSpeechProvider::class);

        $theirMediaId = ingestRecording($container, $theirs);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribe',
            speechRequest('/api/v1/agent-api/transcribe', $key, ['media_id' => $theirMediaId]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('MEDIA_NOT_FOUND');
        expect($provider->calls)->toBe([]);
    });

    it('reads an asset a plugin stored for the agent, which carries no user', function (): void {
        $seed = AgentApiTestSupport::seedAgent('plugin-asset');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'plugin-asset');

        $provider = new StubSpeechProvider();
        $container = speechContainer([$provider]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);

        // A plugin stores an asset for the agent without a user on the row.
        // The reader's ownership predicate cannot see such an asset; the
        // key's reach can, because the agent owns it.
        Capsule::table('media_assets')->where('id', $mediaId)->update(['user_id' => null]);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribe',
            speechRequest('/api/v1/agent-api/transcribe', $key, ['media_id' => $mediaId]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        expect($provider->calls)->toHaveCount(1);
    });

    it('404s an unknown media id', function (): void {
        $seed = AgentApiTestSupport::seedAgent('unknown-media');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'unknown');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribe',
            speechRequest('/api/v1/agent-api/transcribe', $key, ['media_id' => '00000000-0000-4000-8000-000000000000']),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    it('422s a body without a media id', function (): void {
        $seed = AgentApiTestSupport::seedAgent('no-media-id');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'no-media-id');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribe',
            speechRequest('/api/v1/agent-api/transcribe', $key, []),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('VALIDATION_ERROR');
    });

    it('503s when the agent has no driver configured', function (): void {
        $seed = AgentApiTestSupport::seedAgent('no-driver');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'no-driver');

        $provider = new StubSpeechProvider();
        $container = speechContainer([$provider]);

        $mediaId = ingestRecording($container, $seed);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribe',
            speechRequest('/api/v1/agent-api/transcribe', $key, ['media_id' => $mediaId]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('SPEECH_PROVIDER_UNAVAILABLE');
        expect($provider->calls)->toBe([]);
    });

    it('422s a chat id that is not an integer', function (): void {
        $seed = AgentApiTestSupport::seedAgent('bad-chat-id');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'bad-chat-id');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);
        $before = Task::count();

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, [
                'media_id' => $mediaId,
                'chat_id' => 'not-a-number',
            ]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('VALIDATION_ERROR');
        expect(Task::count())->toBe($before);
    });

    it('422s a language that is not a string', function (): void {
        $seed = AgentApiTestSupport::seedAgent('bad-language');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'bad-language');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribe',
            speechRequest('/api/v1/agent-api/transcribe', $key, [
                'media_id' => $mediaId,
                'language' => 42,
            ]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('VALIDATION_ERROR');
    });

    it('sends the transcript as a new chat', function (): void {
        $seed = AgentApiTestSupport::seedAgent('send');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'send');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, ['media_id' => $mediaId]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_ACCEPTED);

        $data = AgentApiTestSupport::decode($response)['data'];
        expect($data['transcription']['text'])->toBe('hello from the recording');

        $chatId = (int) $data['chat']['id'];
        $task = Task::find($chatId);

        expect((int) $task->agent_id)->toBe($seed['agentId']);
        expect($task->user_prompt)->toBe('hello from the recording');

        // The audio is not attached: only the transcript travels, because a
        // model handed raw audio with no text on top guesses at the bytes.
        expect(TaskHistory::where('task_id', $chatId)->where('role', 'attachment')->count())->toBe(0);
    });

    it('sends the transcript with the effort the caller named', function (): void {
        $seed = AgentApiTestSupport::seedAgent('send-effort');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'send-effort');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, [
                'media_id' => $mediaId,
                'effort' => 'high',
            ]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_ACCEPTED);

        $chatId = (int) AgentApiTestSupport::decode($response)['data']['chat']['id'];
        expect(Task::find($chatId)?->effort)->toBe('high');
    });

    it('sends the transcript without an effort when the caller names none', function (): void {
        $seed = AgentApiTestSupport::seedAgent('send-no-effort');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'send-no-effort');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, ['media_id' => $mediaId]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_ACCEPTED);

        $chatId = (int) AgentApiTestSupport::decode($response)['data']['chat']['id'];
        expect(Task::find($chatId)?->effort)->toBeNull();

        // The field is optional, and the endpoint says so: a caller that
        // leaves it out is answered, not refused.
        expect(transcribeAndSendEffortProperty())->not->toBeNull();
    });

    it('refuses an effort the agent\'s LLM configuration does not offer with 422', function (): void {
        $seed = AgentApiTestSupport::seedAgent('send-effort-unsupported', AnthropicCompatibleDriver::class);
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'send-effort-unsupported');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);
        $before = Task::count();

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, [
                'media_id' => $mediaId,
                'effort' => 'none',
            ]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED');
        expect(Task::count())->toBe($before);
    });

    it('puts the caller\'s text after the transcript', function (): void {
        $seed = AgentApiTestSupport::seedAgent('suffix');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'suffix');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, [
                'media_id' => $mediaId,
                'text' => 'and summarise it',
            ]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_ACCEPTED);

        $chatId = (int) AgentApiTestSupport::decode($response)['data']['chat']['id'];

        expect(Task::find($chatId)->user_prompt)
            ->toBe("hello from the recording\n\nand summarise it");
    });

    it('sends the transcript into an existing chat', function (): void {
        $seed = AgentApiTestSupport::seedAgent('continue');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'continue');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);

        $task = Task::create([
            'agent_id' => $seed['agentId'],
            'principal_id' => $seed['principalId'],
            'status' => 'COMPLETED',
            'user_prompt' => 'the first question',
        ]);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, [
                'media_id' => $mediaId,
                'chat_id' => (int) $task->id,
            ]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_ACCEPTED);

        $history = TaskHistory::where('task_id', $task->id)->where('role', 'user')->get();
        expect($history)->toHaveCount(1);
        expect($history->first()->content)->toBe('hello from the recording');
    });

    it('404s a chat that belongs to another agent', function (): void {
        $mine = AgentApiTestSupport::seedAgent('chat-mine');
        $theirs = AgentApiTestSupport::seedAgent('chat-theirs');
        [, $key] = AgentApiTestSupport::mintKey($mine['agentId'], 'chat');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $mine);

        $theirTask = Task::create([
            'agent_id' => $theirs['agentId'],
            'principal_id' => $theirs['principalId'],
            'status' => 'COMPLETED',
            'user_prompt' => 'not yours',
        ]);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, [
                'media_id' => $mediaId,
                'chat_id' => (int) $theirTask->id,
            ]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('NOT_FOUND');
    });

    it('sends nothing when the recording transcribed to no words', function (): void {
        $seed = AgentApiTestSupport::seedAgent('empty');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'empty');

        $provider = new StubSpeechProvider();
        $provider->text = '   ';

        $container = speechContainer([$provider]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);
        $before = Task::count();

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, ['media_id' => $mediaId]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('VALIDATION_ERROR');
        expect(Task::count())->toBe($before);
    });

    it('refuses a callback url that points at a private address, and queues nothing', function (): void {
        $seed = AgentApiTestSupport::seedAgent('ssrf');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'ssrf');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);
        $before = Task::count();

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, [
                'media_id' => $mediaId,
                'callback_url' => 'http://localhost:3000/hooks/spora',
            ]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        expect(Task::count())->toBe($before);
    });

    it('validates the callback url even when the chat already exists', function (): void {
        $seed = AgentApiTestSupport::seedAgent('callback-existing');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'callback-existing');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);

        $task = Task::create([
            'agent_id' => $seed['agentId'],
            'principal_id' => $seed['principalId'],
            'status' => 'COMPLETED',
            'user_prompt' => 'the first question',
        ]);

        // A private address is refused whether the URL will be called or
        // not: the screening happens before the chat is touched, so a
        // caller cannot learn that a URL was accepted by sending one
        // that is not.
        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, [
                'media_id' => $mediaId,
                'chat_id' => (int) $task->id,
                'callback_url' => 'http://localhost:3000/hooks/spora',
            ]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('VALIDATION_ERROR');
    });

    it('calls the callback url only for a chat it starts', function (): void {
        $seed = AgentApiTestSupport::seedAgent('callback-new');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'callback-new');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);

        $task = Task::create([
            'agent_id' => $seed['agentId'],
            'principal_id' => $seed['principalId'],
            'status' => 'COMPLETED',
            'user_prompt' => 'the first question',
        ]);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, [
                'media_id' => $mediaId,
                'chat_id' => (int) $task->id,
                'callback_url' => 'https://example.com/hooks/spora',
            ]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_ACCEPTED);

        // The chat existed already, so nothing is owed to the receiver.
        expect(Capsule::table('api_callbacks')->where('chat_id', $task->id)->count())->toBe(0);
    });

    it('records the callback for the chat it starts', function (): void {
        $seed = AgentApiTestSupport::seedAgent('callback');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'callback');

        $container = speechContainer([new StubSpeechProvider()]);
        registerDriver(StubSpeechProvider::class);

        $mediaId = ingestRecording($container, $seed);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiSpeechController::class,
            'transcribeAndSend',
            speechRequest('/api/v1/agent-api/transcribe-and-send', $key, [
                'media_id' => $mediaId,
                'callback_url' => 'https://example.com/hooks/spora',
            ]),
        );

        expect($response->getStatusCode())->toBe(Response::HTTP_ACCEPTED);

        $chatId = (int) AgentApiTestSupport::decode($response)['data']['chat']['id'];
        $callback = AgentApiTestSupport::callbackFor($chatId);

        expect($callback->url)->toBe('https://example.com/hooks/spora');
    });
});

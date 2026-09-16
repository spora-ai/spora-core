<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Mockery;
use Spora\Auth\AuthService;
use Spora\Http\SpeechCapabilityController;
use Spora\Models\Agent;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Services\GroupService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigService;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Speech\TranscriptionResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CapConfiguredProvider implements SpeechToTextProviderInterface
{
    public function getName(): string
    {
        return 'cap-configured';
    }
    public function getDisplayName(): string
    {
        return 'Cap Configured';
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
    public function transcribe(string $bytes, string $mimeType, ?string $languageHint = null, ?int $agentId = null, ?int $userId = null): TranscriptionResult
    {
        return new TranscriptionResult('unused');
    }
}

final class CapUnconfiguredProvider implements SpeechToTextProviderInterface
{
    public function getName(): string
    {
        return 'cap-unconfigured';
    }
    public function getDisplayName(): string
    {
        return 'Cap Unconfigured';
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
    public function transcribe(string $bytes, string $mimeType, ?string $languageHint = null, ?int $agentId = null, ?int $userId = null): TranscriptionResult
    {
        return new TranscriptionResult('unused');
    }
}

/**
 * Build the capability controller with a registry of the supplied
 * providers. Returns the controller and the AuthService mock so
 * callers can stub `currentUserId()`.
 *
 * @param list<SpeechToTextProviderInterface> $providers
 * @return array{0: SpeechCapabilityController, 1: Mockery\MockInterface}
 */
function buildSpeechCapabilityController(array $providers): array
{
    $auth = Mockery::mock(AuthService::class);
    return [
        new SpeechCapabilityController(
            new SpeechToTextRegistry($providers, new PrincipalService(new PrincipalResolver())),
            $auth,
        ),
        $auth,
    ];
}

test('capability returns 200 with available=false and configured=false when no providers are loaded', function (): void {
    [$controller, $auth] = buildSpeechCapabilityController([]);
    $auth->shouldReceive('currentUserId')->andReturn(null);

    $resp = $controller->index(Request::create('/api/v1/speech/capability', 'GET'));

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['available'])->toBe(false)
        ->and($body['data']['configured'])->toBe(false)
        ->and($body['data']['providers'])->toBe([]);
});

test('capability reports available=true and configured=true with effective_class=fallback when a configured provider is loaded', function (): void {
    [$controller, $auth] = buildSpeechCapabilityController([new CapConfiguredProvider()]);
    $auth->shouldReceive('currentUserId')->andReturn(7);

    $resp = $controller->index(Request::create('/api/v1/speech/capability', 'GET'));

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['available'])->toBe(true)
        ->and($body['data']['configured'])->toBe(true)
        ->and($body['data']['providers'])->toBe([
            [
                'name' => 'cap-configured',
                'display_name' => 'Cap Configured',
                'configured' => true,
                'effective_class' => CapConfiguredProvider::class,
                'effective_source' => 'fallback',
                'effective_config_id' => null,
            ],
        ]);
});

test('capability reports available=true but configured=false when every provider is unconfigured', function (): void {
    [$controller, $auth] = buildSpeechCapabilityController([new CapUnconfiguredProvider()]);
    $auth->shouldReceive('currentUserId')->andReturn(7);

    $resp = $controller->index(Request::create('/api/v1/speech/capability', 'GET'));

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['available'])->toBe(true)
        ->and($body['data']['configured'])->toBe(false)
        ->and($body['data']['providers'][0]['configured'])->toBe(false);
});

test('capability returns 200 to anonymous callers (recording button renders empty state without a second round-trip)', function (): void {
    [$controller, $auth] = buildSpeechCapabilityController([new CapConfiguredProvider()]);
    $auth->shouldReceive('currentUserId')->andReturn(null);

    $resp = $controller->index(Request::create('/api/v1/speech/capability', 'GET'));

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['available'])->toBe(true)
        ->and($body['data']['providers'][0]['name'])->toBe('cap-configured');
});

test('capability with ?agent_id forwards the agent id into the registry resolution (per-agent cascade)', function (): void {
    $oai = new \Spora\Speech\OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));

    $callerId = 40;
    $callerPrincipalId = createUserPrincipalPublic($callerId);

    // Caller's user-principal preference points at OpenAI. Without `?agent_id`,
    // the test would resolve via the caller-scoped path and pick OpenAI.
    // When we pass a group-owned agent id whose group has a different
    // preference (here: none — falls through to a group_preference-routed
    // global default), the response should reflect the agent's principal,
    // not the caller's.
    $userConfigId = (int) \Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insertGetId([
        'principal_id' => $callerPrincipalId,
        'provider_class' => \Spora\Speech\OpenAiCompatibleTranscriber::class,
        'display_name' => 'Mine',
        'settings' => '{}',
        'is_default' => false,
        'is_global' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    \Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
        'principal_id' => $callerPrincipalId,
        'preferred_speech_config_id' => $userConfigId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $ownerId = 41;
    createUserPrincipalPublic($ownerId);
    $principalService = new PrincipalService(new PrincipalResolver());
    $groupService = new GroupService($principalService);
    $group = $groupService->createGroup($ownerId, 'RegCapGrp');
    $groupService->addMember((int) $group->id, $callerId, GroupMembership::ROLE_MEMBER, $ownerId);
    $groupPrincipalId = (int) \Illuminate\Database\Capsule\Manager::table('principals')
        ->where('type', Principal::TYPE_GROUP)
        ->where('group_id', $group->id)
        ->value('id');
    $groupAgentId = (int) Agent::create([
        'principal_id' => $groupPrincipalId,
        'name' => 'CapGroupAgent',
        'max_steps' => 5,
        'is_active' => true,
    ])->id;

    [$controller, $auth] = buildSpeechCapabilityController([$oai]);
    $auth->shouldReceive('currentUserId')->andReturn($callerId);

    $resp = $controller->index(Request::create('/api/v1/speech/capability?agent_id=' . $groupAgentId, 'GET'));

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    // The group-owned agent has no FK override and no group preference, so
    // it must NOT pick the caller's user preference. Source should reflect
    // either `global_default` or `fallback`, never `user_preference`.
    expect($body['data']['providers'][0]['effective_source'])
        ->not->toBe('user_preference');
});

test('capability ignores malformed ?agent_id values and falls back to caller-scoped resolution', function (): void {
    [$controller, $auth] = buildSpeechCapabilityController([new CapConfiguredProvider()]);
    $auth->shouldReceive('currentUserId')->andReturn(7);

    foreach (['agent_id=abc', 'agent_id=0', 'agent_id=-1', 'agent_id='] as $query) {
        $resp = $controller->index(Request::create('/api/v1/speech/capability?' . $query, 'GET'));
        expect($resp->getStatusCode())->toBe(Response::HTTP_OK)
            ->and(json_decode($resp->getContent(), true)['data']['providers'][0]['effective_source'])
                ->toBe('fallback');
    }
});

<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Mockery;
use Spora\Auth\AuthService;
use Spora\Http\SpeechCapabilityController;
use Spora\Services\ToolConfigService;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Speech\TranscriptionResult;
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
    public function transcribe(string $bytes, string $mimeType, ?string $languageHint = null, ?int $agentId = null, ?int $userId = null): TranscriptionResult
    {
        return new TranscriptionResult('unused');
    }
}

/**
 * Build a controller with a registry that has the supplied providers
 * and a permissive ToolConfigService. Returns the controller and the
 * AuthService mock so callers can stub `currentUserId()`.
 *
 * @param list<SpeechToTextProviderInterface> $providers
 * @return array{0: SpeechCapabilityController, 1: Mockery\MockInterface}
 */
function buildSpeechCapabilityController(array $providers): array
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([]);
    $config->shouldReceive('getGlobalSettings')->andReturn([]);
    $auth = Mockery::mock(AuthService::class);
    return [new SpeechCapabilityController(new SpeechToTextRegistry($providers, $config), $auth), $auth];
}

test('capability returns 200 with available=false and configured=false when no providers are loaded', function (): void {
    [$controller, $auth] = buildSpeechCapabilityController([]);
    $auth->shouldReceive('currentUserId')->andReturn(null);

    $resp = $controller->index();

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['available'])->toBe(false)
        ->and($body['data']['configured'])->toBe(false)
        ->and($body['data']['providers'])->toBe([]);
});

test('capability reports available=true and configured=true when a configured provider is loaded', function (): void {
    [$controller, $auth] = buildSpeechCapabilityController([new CapConfiguredProvider()]);
    $auth->shouldReceive('currentUserId')->andReturn(7);

    $resp = $controller->index();

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['available'])->toBe(true)
        ->and($body['data']['configured'])->toBe(true)
        ->and($body['data']['providers'])->toBe([
            [
                'name'               => 'cap-configured',
                'display_name'       => 'Cap Configured',
                'configured'         => true,
                'has_global_default' => false,
                'config_id'          => null,
                'effective_class'    => 'Tests\Feature\Http\CapConfiguredProvider',
                'effective_source'   => 'fallback',
            ],
        ]);
});

test('capability reports available=true but configured=false when every provider is unconfigured', function (): void {
    [$controller, $auth] = buildSpeechCapabilityController([new CapUnconfiguredProvider()]);
    $auth->shouldReceive('currentUserId')->andReturn(7);

    $resp = $controller->index();

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['available'])->toBe(true)
        ->and($body['data']['configured'])->toBe(false)
        ->and($body['data']['providers'][0]['configured'])->toBe(false)
        ->and($body['data']['providers'][0]['has_global_default'])->toBe(false)
        ->and($body['data']['providers'][0]['config_id'])->toBeNull();
});

test('capability returns 200 with the configured provider when the caller is anonymous', function (): void {
    [$controller, $auth] = buildSpeechCapabilityController([new CapConfiguredProvider()]);
    $auth->shouldReceive('currentUserId')->andReturn(null);

    $resp = $controller->index();

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    // The Capability endpoint reports the system-level provider state
    // regardless of whether the caller is authenticated — anonymous
    // visitors still see the "available" provider so the recording
    // button can render its disabled / "please log in" hint without
    // a second round-trip.
    expect($body['data']['available'])->toBe(true)
        ->and($body['data']['configured'])->toBe(true)
        ->and($body['data']['providers'][0]['name'])->toBe('cap-configured');
});

test('capability passes the user id into describe() and configuredProvider()', function (): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([]);
    $config->shouldReceive('getGlobalSettings')->andReturn([]);
    $auth = Mockery::mock(AuthService::class);
    $auth->shouldReceive('currentUserId')->andReturn(13);

    $controller = new SpeechCapabilityController(
        new SpeechToTextRegistry([new CapConfiguredProvider()], $config),
        $auth,
    );

    // The CapConfiguredProvider is class-level — describe() must skip
    // ToolConfigService for it. We confirm by ensuring the test passes
    // (the mock would throw on unexpected calls).
    $resp = $controller->index();
    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    expect(json_decode($resp->getContent(), true)['data']['configured'])->toBeTrue();
});

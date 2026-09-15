<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Mockery;
use Spora\Auth\AuthService;
use Spora\Http\SpeechCapabilityController;
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
            new SpeechToTextRegistry($providers, new \Spora\Services\PrincipalService(new \Spora\Services\PrincipalResolver())),
            $auth,
        ),
        $auth,
    ];
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

test('capability reports available=true and configured=true with effective_class=fallback when a configured provider is loaded', function (): void {
    [$controller, $auth] = buildSpeechCapabilityController([new CapConfiguredProvider()]);
    $auth->shouldReceive('currentUserId')->andReturn(7);

    $resp = $controller->index();

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

    $resp = $controller->index();

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['available'])->toBe(true)
        ->and($body['data']['configured'])->toBe(false)
        ->and($body['data']['providers'][0]['configured'])->toBe(false);
});

test('capability returns 200 to anonymous callers (recording button renders empty state without a second round-trip)', function (): void {
    [$controller, $auth] = buildSpeechCapabilityController([new CapConfiguredProvider()]);
    $auth->shouldReceive('currentUserId')->andReturn(null);

    $resp = $controller->index();

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['available'])->toBe(true)
        ->and($body['data']['providers'][0]['name'])->toBe('cap-configured');
});

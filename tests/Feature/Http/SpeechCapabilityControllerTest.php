<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Http\SpeechCapabilityController;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Speech\TranscriptionResult;
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
    public function transcribe(string $bytes, string $mimeType, ?string $languageHint = null): TranscriptionResult
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
    public function transcribe(string $bytes, string $mimeType, ?string $languageHint = null): TranscriptionResult
    {
        return new TranscriptionResult('unused');
    }
}

test('capability returns 200 with available=false and configured=false when no providers are loaded', function (): void {
    $controller = new SpeechCapabilityController(new SpeechToTextRegistry([]));

    $resp = $controller->index(new Request());

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['available'])->toBe(false)
        ->and($body['data']['configured'])->toBe(false)
        ->and($body['data']['providers'])->toBe([]);
});

test('capability reports available=true and configured=true when a configured provider is loaded', function (): void {
    $controller = new SpeechCapabilityController(new SpeechToTextRegistry([
        new CapConfiguredProvider(),
    ]));

    $resp = $controller->index(new Request());

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['available'])->toBe(true)
        ->and($body['data']['configured'])->toBe(true)
        ->and($body['data']['providers'])->toBe([
            ['name' => 'cap-configured', 'display_name' => 'Cap Configured', 'configured' => true],
        ]);
});

test('capability reports available=true but configured=false when every provider is unconfigured', function (): void {
    $controller = new SpeechCapabilityController(new SpeechToTextRegistry([
        new CapUnconfiguredProvider(),
    ]));

    $resp = $controller->index(new Request());

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['available'])->toBe(true)
        ->and($body['data']['configured'])->toBe(false)
        ->and($body['data']['providers'][0]['configured'])->toBe(false);
});

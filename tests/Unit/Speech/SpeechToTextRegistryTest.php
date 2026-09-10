<?php

declare(strict_types=1);

use Spora\Speech\InvalidAudioException;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Speech\TranscriptionResult;

/**
 * Pin the registry selection rules: first-configured-wins, describe()
 * shape, empty-list behaviour.
 */

final class StubConfiguredProvider implements SpeechToTextProviderInterface
{
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
        return new TranscriptionResult('stub');
    }
}

final class StubUnconfiguredProvider implements SpeechToTextProviderInterface
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
        throw new InvalidAudioException('never called');
    }
}

test('empty registry — all, configured, describe all return empty', function (): void {
    $registry = new SpeechToTextRegistry([]);

    expect($registry->all())->toBe([])
        ->and($registry->configuredProvider())->toBeNull()
        ->and($registry->describe())->toBe([]);
});

test('configuredProvider() returns null when every provider reports unconfigured', function (): void {
    $registry = new SpeechToTextRegistry([
        new StubUnconfiguredProvider(),
        new StubUnconfiguredProvider(),
    ]);

    expect($registry->configuredProvider())->toBeNull();
});

test('configuredProvider() returns the first configured entry (insertion order wins)', function (): void {
    $configured = new StubConfiguredProvider();
    $unconfigured = new StubUnconfiguredProvider();

    $registry = new SpeechToTextRegistry([$unconfigured, $configured]);

    expect($registry->configuredProvider())->toBe($configured);
});

test('describe() emits name + display_name + configured for every provider, in order', function (): void {
    $a = new StubConfiguredProvider();
    $b = new StubUnconfiguredProvider();

    $registry = new SpeechToTextRegistry([$a, $b]);

    expect($registry->describe())->toBe([
        ['name' => 'stub-configured',   'display_name' => 'Stub Configured',   'configured' => true],
        ['name' => 'stub-unconfigured', 'display_name' => 'Stub Unconfigured', 'configured' => false],
    ]);
});

test('all() returns the providers in their constructor order', function (): void {
    $a = new StubConfiguredProvider();
    $b = new StubUnconfiguredProvider();

    $registry = new SpeechToTextRegistry([$a, $b]);

    expect($registry->all())->toBe([$a, $b]);
});

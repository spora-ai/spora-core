<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\SpeechProviderPlugin;

use Spora\Speech\InvalidAudioException;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\TranscriptionResult;

/**
 * Test fixture provider — exercises the plugin discovery path on
 * {@see \Spora\Plugins\PluginLoader::speechToTextProviderClasses()}.
 * Reports `isConfigured() === false` so no real STT call is attempted
 * during the test; throws if transcribe() ever gets called.
 */
final class FixtureProvider implements SpeechToTextProviderInterface
{
    public function getName(): string
    {
        return 'fixture';
    }
    public function getDisplayName(): string
    {
        return 'Fixture Provider';
    }
    public function isConfigured(): bool
    {
        return false;
    }
    public function bindLabel(string $label): void
    {
        // no-op for the test fixture — fixture never picks up a per-config label
    }
    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult {
        throw new InvalidAudioException('not implemented in fixture');
    }
}

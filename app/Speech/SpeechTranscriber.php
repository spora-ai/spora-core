<?php

declare(strict_types=1);

namespace Spora\Speech;

use Psr\Log\LoggerInterface;
use Spora\Http\Exceptions\SpeechTranscribeException;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;

/**
 * The one place a recording becomes text.
 *
 * Two surfaces drive it: the SPA's recorder button
 * ({@see \Spora\Http\SpeechTranscribeController}) and the API-key
 * surfaces ({@see \Spora\Http\AgentApi\AgentApiSpeechController}). They
 * have to agree on which provider a recording goes to — the agent's
 * pinned `speech_driver_config_id` first, then the agent's principal
 * preference, then the global default — because a caller that recorded
 * in the browser and a caller that posted a file to the same agent are
 * asking the same agent the same question.
 *
 * The provider's own settings lookup never sees the agent id. The v2
 * cascade (the resolved configuration row, pushed in by
 * {@see SpeechToTextRegistry::configuredProvider()}) is the source of
 * truth, and threading the agent id would let the v1
 * `agent_tool_overrides` path pick a different API key on a surface the
 * SPA does not use.
 *
 * Failures carry their status and error code on
 * {@see SpeechTranscribeException}, so a controller maps a branch
 * without rebuilding the wire shape.
 */
final class SpeechTranscriber
{
    public function __construct(
        private readonly SpeechToTextRegistry $registry,
        private readonly MediaAssetReader $mediaReader,
        private readonly MediaArchiveService $mediaArchive,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Transcribe an asset the caller owns.
     *
     * @throws SpeechTranscribeException
     */
    public function transcribeOwned(
        string $mediaId,
        int $agentId,
        int $userId,
        ?string $language = null,
    ): TranscriptionResult {
        return $this->run($mediaId, $userId, $agentId, $userId, $language);
    }

    /**
     * Transcribe an asset the caller reached by a scope of its own.
     *
     * The API-key surfaces reach an asset through the key's own rules,
     * which are wider than the reader's user-based predicate: an asset
     * a plugin stored for the agent carries no `user_id`, so the reader
     * would refuse an asset the key may read. The caller has already
     * decided the asset is readable — this only skips the reader's
     * narrower check.
     *
     * @throws SpeechTranscribeException
     */
    public function transcribeReachable(
        string $mediaId,
        int $agentId,
        int $userId,
        ?string $language = null,
    ): TranscriptionResult {
        return $this->run($mediaId, null, $agentId, $userId, $language);
    }

    /**
     * The provider is resolved before the asset is read, so a caller with
     * no driver at all is told that rather than being sent after a media
     * id it never had.
     *
     * @param int|null $ownerUserId whose ownership of the asset is
     *        checked; null skips the check, for a caller that authorised
     *        the read by another rule.
     *
     * @throws SpeechTranscribeException
     */
    private function run(
        string $mediaId,
        ?int $ownerUserId,
        int $agentId,
        int $userId,
        ?string $language,
    ): TranscriptionResult {
        $provider = $this->requireConfiguredProvider($userId, $agentId);
        $asset = $this->loadAsset($mediaId, $ownerUserId);
        $result = $this->callProvider($provider, $asset, $language, $userId);

        // Cached on the row so a re-render of the asset reuses the
        // transcript instead of paying the provider again.
        $this->mediaArchive->writeTranscript($mediaId, $result);

        return $result;
    }

    /**
     * @throws SpeechTranscribeException 503 when no provider reports configured.
     */
    private function requireConfiguredProvider(int $userId, int $agentId): SpeechToTextProviderInterface
    {
        $provider = $this->registry->configuredProvider($userId, $agentId);

        if ($provider === null) {
            throw SpeechTranscribeException::providerUnavailable(
                'No speech-to-text provider is configured. Add an API key in Settings → Tools for OpenAI-compatible STT (Mistral, OpenAI Whisper, Groq, etc.) or install spora-plugin-muse.',
            );
        }

        return $provider;
    }

    /**
     * @return array{status: 'data_url', bytes: string, mime: string}
     *         | array{status: 'local', bytes: string, mime: string}
     *
     * @throws SpeechTranscribeException 404 when the reader yields null
     *         (missing / unauthorized / legacy storage mode — no
     *         existence leak between reasons);
     *         422 INVALID_AUDIO when the asset is only an external
     *         `source_url` pointer (the provider needs bytes, not a URL).
     */
    private function loadAsset(string $mediaId, ?int $ownerUserId): array
    {
        $asset = $this->mediaReader->readAsset($mediaId, $ownerUserId);

        if ($asset === null) {
            throw SpeechTranscribeException::mediaNotFound('Media asset not found or not accessible.');
        }

        if ($asset['status'] === 'data_url' || $asset['status'] === 'local') {
            return $asset;
        }

        // The reader's PHPDoc union is exhaustive (data_url | local | external);
        // the only remaining value is `external`, which the provider can't
        // consume (it needs bytes, not a URL).
        throw SpeechTranscribeException::invalidAudio(
            'External media assets cannot be transcribed without first being promoted to local storage.',
        );
    }

    /**
     * @param array{status: 'data_url', bytes: string, mime: string}
     *       | array{status: 'local', bytes: string, mime: string} $asset
     *
     * @throws SpeechTranscribeException 422 / 502 on provider failure.
     */
    private function callProvider(
        SpeechToTextProviderInterface $provider,
        array $asset,
        ?string $language,
        int $userId,
    ): TranscriptionResult {
        try {
            return $provider->transcribe(
                $asset['bytes'],
                $asset['mime'],
                $language,
                0,
                $userId,
            );
        } catch (InvalidAudioException $e) {
            throw SpeechTranscribeException::invalidAudio($e->getMessage());
        } catch (SpeechToTextException $e) {
            $this->logger?->error('STT provider failed', [
                'provider' => $provider->getName(),
                'message'  => $e->getMessage(),
            ]);
            throw SpeechTranscribeException::providerFailed($e->getMessage());
        }
    }
}

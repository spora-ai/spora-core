<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Spora\Auth\AuthService;
use Spora\Http\Exceptions\SpeechTranscribeException;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Speech\InvalidAudioException;
use Spora\Speech\SpeechToTextException;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Speech\TranscriptionResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Transcribe a recorded audio asset via the first-configured STT plugin.
 *
 * Flow:
 *   1. Decode the JSON body, validate `{ media_id, language? }`.
 *   2. Resolve the configured provider from {@see SpeechToTextRegistry}.
 *      503 if none is configured.
 *   3. Read the asset via {@see MediaAssetReader::readAsset()} — three
 *      outcomes, mapped to wire status codes (no existence leak
 *      between the 404 variants):
 *        - `null` (missing / unauthorized / legacy storage mode) → 404
 *        - `external` (asset is just a `source_url` pointer) → 422
 *          INVALID_AUDIO; the provider needs bytes, not a URL
 *        - `data_url` / `local` → continue with `bytes` + `mime`
 *   4. Call the provider's `transcribe(bytes, mime, language?, agentId?, userId?)`.
 *      422 on InvalidAudioException (client-side fix);
 *      502 on SpeechToTextException (provider-side failure).
 *   5. Write the transcript back to `media_assets.transcript` /
 *      `media_assets.transcript_language` so chat re-renders re-use the
 *      cached value without re-billing.
 *   6. Return the wire-shape result.
 *
 * Provider API keys NEVER leave the server. The provider's exception
 * message is logged server-side (operator-visible) and the sanitised
 * form is forwarded to the API per the {@see SpeechToTextException}
 * contract.
 *
 * Branches raise {@see SpeechTranscribeException} instead of building a
 * JsonResponse inline so the success path stays readable and the
 * `return` count of the handler stays below the Sonar brain-overload
 * threshold.
 */
final class SpeechTranscribeController
{
    public function __construct(
        private readonly SpeechToTextRegistry $registry,
        private readonly MediaAssetReader $mediaReader,
        private readonly MediaArchiveService $mediaArchive,
        private readonly AuthService $auth,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    #[OA\Post(
        path: '/api/v1/speech/transcribe',
        summary: 'Transcribe a recorded audio asset',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['media_id'],
                properties: [
                    new OA\Property(property: 'media_id', type: 'string', format: 'uuid'),
                    new OA\Property(
                        property: 'language',
                        type: 'string',
                        nullable: true,
                        description: 'BCP-47 hint (e.g. "en-US"). null = auto-detect.',
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transcript',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'text', type: 'string'),
                        new OA\Property(property: 'language', type: 'string', nullable: true),
                        new OA\Property(property: 'duration_ms', type: 'number', nullable: true),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'INVALID_AUDIO or validation error'),
            new OA\Response(response: 404, description: 'MEDIA_NOT_FOUND'),
            new OA\Response(response: 502, description: 'SPEECH_PROVIDER_FAILED'),
            new OA\Response(response: 503, description: 'SPEECH_PROVIDER_UNAVAILABLE'),
        ],
    )]
    public function transcribe(Request $request): JsonResponse
    {
        try {
            $userId   = $this->requireUserId();
            $payload  = $this->decodeBody($request);
            $provider = $this->requireConfiguredProvider();
            $asset    = $this->loadAsset($payload['media_id'], $userId);
            // agent_id is intentionally null — MediaAssetReader::readAsset()
            // returns bytes+mime only. Provider settings cascade from global
            // down (and through the user principal level via $userId).
            // Per-agent override is out of scope for v1.
            $result   = $this->transcribeWithProvider(
                $provider,
                $asset,
                $payload['language'] ?? null,
                $userId,
            );
        } catch (SpeechTranscribeException $e) {
            return $this->error($e->statusCode, $e->errorCode, $e->getMessage());
        }

        $this->mediaArchive->writeTranscript($payload['media_id'], $result);

        return new JsonResponse([
            'data' => [
                'text'        => $result->text,
                'language'    => $result->language,
                'duration_ms' => $result->durationMs,
            ],
        ]);
    }

    /**
     * @return array{media_id: string, language?: string}
     *
     * @throws SpeechTranscribeException 422 on malformed body.
     */
    private function decodeBody(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SpeechTranscribeException::validation('Request body must be valid JSON.');
        }

        if (!is_array($body) || !isset($body['media_id']) || !is_string($body['media_id']) || $body['media_id'] === '') {
            throw SpeechTranscribeException::validation('Body must include a non-empty "media_id" string.');
        }

        if (isset($body['language']) && !is_string($body['language'])) {
            throw SpeechTranscribeException::validation('"language" must be a string when present.');
        }

        /** @var array{media_id: string, language?: string} $body */
        return $body;
    }

    /**
     * @throws SpeechTranscribeException 401 when no session is bound to the request.
     */
    private function requireUserId(): int
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            throw SpeechTranscribeException::unauthorized('You must be logged in to transcribe audio.');
        }

        return $userId;
    }

    /**
     * @throws SpeechTranscribeException 503 when no provider reports configured.
     */
    private function requireConfiguredProvider(): SpeechToTextProviderInterface
    {
        $provider = $this->registry->configuredProvider();
        if ($provider === null) {
            throw SpeechTranscribeException::providerUnavailable(
                'No speech-to-text provider is configured. Install spora-plugin-mistral or spora-plugin-muse and add an API key.',
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
    private function loadAsset(string $mediaId, int $userId): array
    {
        $asset = $this->mediaReader->readAsset($mediaId, $userId);
        if ($asset === null) {
            throw SpeechTranscribeException::mediaNotFound('Media asset not found or not accessible.');
        }

        $status = $asset['status'];
        if ($status === 'data_url' || $status === 'local') {
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
    private function transcribeWithProvider(
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
                null,
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

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
        );
    }
}

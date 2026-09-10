<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Spora\Auth\AuthService;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Speech\InvalidAudioException;
use Spora\Speech\SpeechToTextException;
use Spora\Speech\SpeechToTextRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transcribe a recorded audio asset via the first-configured STT plugin.
 *
 * Flow:
 *   1. Decode the JSON body, validate `{ media_id, language? }`.
 *   2. Resolve the configured provider from {@see SpeechToTextRegistry}.
 *      503 if none is configured.
 *   3. Read the asset bytes via {@see MediaAssetReader::readAsset()}.
 *      null return covers missing / unauthorized / legacy storage mode —
 *      all three map to 404 (no existence leak between reasons).
 *   4. Call the provider's `transcribe(bytes, mime, language?)`.
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
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->error(Response::HTTP_UNAUTHORIZED, 'UNAUTHORIZED', 'You must be logged in to transcribe audio.');
        }

        $payload = $this->decodeBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $mediaId = $payload['media_id'];
        $language = $payload['language'] ?? null;

        $provider = $this->registry->configuredProvider();
        if ($provider === null) {
            return $this->error(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'SPEECH_PROVIDER_UNAVAILABLE',
                'No speech-to-text provider is configured. Install spora-plugin-mistral or spora-plugin-muse and add an API key.',
            );
        }

        $asset = $this->mediaReader->readAsset($mediaId, $userId);
        if ($asset === null) {
            return $this->error(
                Response::HTTP_NOT_FOUND,
                'MEDIA_NOT_FOUND',
                'Media asset not found or not accessible.',
            );
        }

        // agent_id is intentionally null here — MediaAssetReader::readAsset()
        // returns bytes+mime only. Provider settings cascade from global down
        // (and through the user principal level via $userId). Per-agent
        // override is out of scope for v1.
        try {
            $result = $provider->transcribe(
                $asset['bytes'],
                $asset['mime'],
                is_string($language) ? $language : null,
                null,
                $userId,
            );
        } catch (InvalidAudioException $e) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'INVALID_AUDIO', $e->getMessage());
        } catch (SpeechToTextException $e) {
            $this->logger?->error('STT provider failed', [
                'provider' => $provider->getName(),
                'message'  => $e->getMessage(),
            ]);
            return $this->error(Response::HTTP_BAD_GATEWAY, 'SPEECH_PROVIDER_FAILED', $e->getMessage());
        }

        $this->mediaArchive->writeTranscript($mediaId, $result);

        return new JsonResponse([
            'data' => [
                'text'        => $result->text,
                'language'    => $result->language,
                'duration_ms' => $result->durationMs,
            ],
        ]);
    }

    /**
     * Decode + validate the request body.
     *
     * @return array{media_id: string, language?: string}|JsonResponse
     */
    private function decodeBody(Request $request): array|JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_ERROR', 'Request body must be valid JSON.');
        }

        if (!is_array($body) || !isset($body['media_id']) || !is_string($body['media_id']) || $body['media_id'] === '') {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_ERROR', 'Body must include a non-empty "media_id" string.');
        }

        if (isset($body['language']) && !is_string($body['language'])) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_ERROR', '"language" must be a string when present.');
        }

        return $body;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
        );
    }
}

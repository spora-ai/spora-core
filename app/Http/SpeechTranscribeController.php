<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Http\Exceptions\SpeechTranscribeException;
use Spora\Services\AgentService;
use Spora\Speech\SpeechToTextException;
use Spora\Speech\SpeechTranscriber;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Transcribe a recorded audio asset with the operator's configured driver.
 *
 * The session-scoped counterpart of
 * {@see \Spora\Http\AgentApi\AgentApiSpeechController}: both drive the
 * same {@see SpeechTranscriber}, so a recording transcribed in the
 * browser and one posted to the agent's key reach the same provider.
 *
 * The caller owns the asset — the request names it and the reader
 * checks that the session's user may read it. The API-key surface
 * reaches assets by the key's own rules instead, and says so by calling
 * a different method on the transcriber.
 *
 * Optional `agent_id`: when present, the controller validates that the
 * caller owns the agent (`AgentService::getAgent($agentId, $userId)`)
 * — a 422 `VALIDATION_ERROR` surfaces a non-owned id without leaking
 * whether it exists. The id IS threaded into the registry
 * (`configuredProvider($userId, $agentId)` — tier 1 reads from
 * `agents.speech_driver_config_id`) but is NOT threaded into the
 * provider call itself: the provider reads user / group / global
 * settings from `SpeechProviderConfiguration` directly, so it
 * receives `0` for the `$agentId` argument.
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
        private readonly SpeechTranscriber $transcriber,
        private readonly AuthService $auth,
        private readonly AgentService $agentService,
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
                    new OA\Property(
                        property: 'agent_id',
                        type: 'integer',
                        nullable: true,
                        description: 'Optional agent id (must be owned by the caller). Accepted for backwards compatibility — the controller validates ownership but the speech cascade no longer threads agent_id into settings lookup, so per-agent STT overrides are out of scope.',
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
            $userId  = $this->requireUserId();
            $payload = $this->decodeBody($request);
            $agentId = $this->resolveAgentId($payload, $userId);

            $result = $this->transcriber->transcribeOwned(
                $payload['media_id'],
                $agentId ?? 0,
                $userId,
                $payload['language'] ?? null,
            );
        } catch (SpeechTranscribeException $e) {
            return $this->error($e->statusCode, $e->errorCode, $e->getMessage());
        }

        return new JsonResponse([
            'data' => [
                'text'        => $result->text,
                'language'    => $result->language,
                'duration_ms' => $result->durationMs,
            ],
        ]);
    }

    /**
     * @return array{media_id: string, language?: string, agent_id?: int}
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

        if (isset($body['agent_id']) && (!is_int($body['agent_id']) || $body['agent_id'] < 0)) {
            throw SpeechTranscribeException::validation('"agent_id" must be a non-negative integer when present.');
        }

        /** @var array{media_id: string, language?: string, agent_id?: int} $body */
        return $body;
    }

    /**
     * Resolve the agent id from the request body (optional). When set,
     * the agent must belong to the requesting user; any failure surfaces
     * 422 `VALIDATION_ERROR` so a probing caller cannot distinguish
     * "doesn't exist" from "not yours".
     *
     * Composers that don't know the agent (e.g. a global "New Chat"
     * picker) omit the field and the cascade falls through to user /
     * group / global as before. Passing `0` is treated as "no agent".
     *
     * @param array{media_id: string, agent_id?: int} $payload
     *
     * @throws SpeechTranscribeException 422 when the agent is not owned by the caller.
     */
    private function resolveAgentId(array $payload, int $userId): ?int
    {
        if (!isset($payload['agent_id']) || $payload['agent_id'] <= 0) {
            return null;
        }

        $agentId = $payload['agent_id'];
        $agent = $this->agentService->getAgent($agentId, $userId);
        if ($agent === null) {
            throw SpeechTranscribeException::validation(
                "agent_id {$agentId} does not belong to the requesting user.",
            );
        }

        return $agentId;
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

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
        );
    }
}

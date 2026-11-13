<?php

declare(strict_types=1);

namespace Spora\Http\AgentApi;

use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Spora\Http\EffortOpenApi;
use Spora\Http\Exceptions\EffortValidationException;
use Spora\Http\Exceptions\SpeechTranscribeException;
use Spora\Http\JsonControllerHelpers;
use Spora\Http\TaskEffortRequestValidator;
use Spora\Services\ApiKeys\ApiKeyCallbackRecorder;
use Spora\Services\ApiKeys\ApiKeyChatAccess;
use Spora\Services\ApiKeys\ApiKeyChatProjection;
use Spora\Services\ApiKeys\ApiKeyContext;
use Spora\Services\ApiKeys\ApiKeyMediaAccess;
use Spora\Services\EffortService;
use Spora\Services\SsrfGuard;
use Spora\Speech\SpeechTranscriber;
use Spora\Speech\TranscriptionResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Voice input for the Agent API.
 *
 * The SPA's recorder button offers a caller two things: read the
 * transcript back, or send it to the agent. These endpoints are those
 * two things over HTTP — `transcribe` for the first, `transcribeAndSend`
 * for the second, which starts a chat or continues one.
 *
 * The provider is the one the agent's own cascade resolves — the
 * agent's pinned `speech_driver_config_id`, then the agent's principal
 * preference, then the global default — so a recording posted here
 * reaches the same driver the browser's recorder would have used.
 *
 * The audio is not attached to the chat. Only the transcript travels:
 * handing a model raw audio with no text on top makes it guess at the
 * bytes and answer "couldn't extract any text from the attached file".
 * The asset keeps the transcript on its own row either way.
 *
 * The media id is checked against the key's own reach
 * ({@see ApiKeyMediaAccess}), not against the reader's user-based
 * predicate: an asset a plugin stored for the agent carries no
 * `user_id`, and the key may read it all the same.
 */
#[OA\Tag(name: 'Agent-api', description: 'Per-agent API for external tools')]
final class AgentApiSpeechController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly ApiKeyChatAccess $scope,
        private readonly ApiKeyChatProjection $projection,
        private readonly ApiKeyMediaAccess $media,
        private readonly ApiKeyCallbackRecorder $callbacks,
        private readonly SpeechTranscriber $transcriber,
        private readonly SsrfGuard $ssrf,
        private readonly EffortService $effortService,
    ) {}

    #[OA\Post(
        path: '/api/v1/agent-api/transcribe',
        summary: "Transcribe an audio asset with this agent's driver",
        tags: ['Agent-api'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['media_id'],
                properties: [
                    new OA\Property(property: 'media_id', type: 'string', description: 'The audio asset to transcribe.'),
                    new OA\Property(
                        property: 'language',
                        type: 'string',
                        nullable: true,
                        description: 'BCP-47 hint (e.g. "en-US"). Omit to auto-detect.',
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'The transcript.'),
            new OA\Response(response: 404, description: 'MEDIA_NOT_FOUND — no such asset on this agent.')  ,
            new OA\Response(response: 422, description: 'INVALID_AUDIO or validation error'),
            new OA\Response(response: 502, description: 'SPEECH_PROVIDER_FAILED'),
            new OA\Response(response: 503, description: 'SPEECH_PROVIDER_UNAVAILABLE'),
        ],
    )]
    public function transcribe(Request $request): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $body = $this->safeDecodeJson($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $prepared = $this->prepareTranscribe($body);

        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        $result = $this->transcribeMedia(
            $context,
            $prepared['mediaId'],
            $prepared['language'],
        );

        return $result instanceof JsonResponse
            ? $result
            : new JsonResponse(['data' => $this->transcriptionPayload($prepared['mediaId'], $result)]);
    }

    #[OA\Post(
        path: '/api/v1/agent-api/transcribe-and-send',
        summary: "Transcribe an audio asset and send the transcript to this agent",
        tags: ['Agent-api'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['media_id'],
                properties: [
                    new OA\Property(property: 'media_id', type: 'string', description: 'The audio asset to transcribe.'),
                    new OA\Property(
                        property: 'chat_id',
                        type: 'integer',
                        nullable: true,
                        description: 'Send into this chat instead of starting a new one.',
                    ),
                    new OA\Property(
                        property: 'text',
                        type: 'string',
                        nullable: true,
                        description: 'Appended after the transcript, separated by a blank line.',
                    ),
                    new OA\Property(
                        property: 'language',
                        type: 'string',
                        nullable: true,
                        description: 'BCP-47 hint (e.g. "en-US"). Omit to auto-detect.',
                    ),
                    new OA\Property(
                        property: 'callback_url',
                        type: 'string',
                        format: 'uri',
                        nullable: true,
                        description: 'Screened as on POST /chats. Used only when a new chat is started — with `chat_id` the URL is still validated, but nothing is called.',
                    ),
                    new OA\Property(property: 'max_steps', type: 'integer', nullable: true, description: 'New chat only.'),
                    new OA\Property(property: 'additional_steps', type: 'integer', nullable: true, description: 'Existing chat only.'),
                    new OA\Property(
                        property: 'effort',
                        type: 'string',
                        nullable: true,
                        description: EffortOpenApi::FIELD_DESCRIPTION,
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 202, description: 'The transcript was queued as a message.'),
            new OA\Response(response: 404, description: 'No such chat or media on this agent.')  ,
            new OA\Response(response: 409, description: 'The chat is not in a state that can be continued.')  ,
            new OA\Response(response: 422, description: 'INVALID_AUDIO or validation error'),
            new OA\Response(response: 502, description: 'SPEECH_PROVIDER_FAILED'),
            new OA\Response(response: 503, description: 'SPEECH_PROVIDER_UNAVAILABLE'),
        ],
    )]
    public function transcribeAndSend(Request $request): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $body = $this->safeDecodeJson($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        try {
            $prepared = $this->prepareSend($body, $context);
        } catch (EffortValidationException $e) {
            return TaskEffortRequestValidator::errorResponse($e);
        }

        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        return $this->send($context, $prepared);
    }

    /**
     * Transcribe, then put the transcript in front of the agent.
     *
     * @param array{
     *     mediaId: string,
     *     language: ?string,
     *     extra: ?string,
     *     chatId: ?int,
     *     callbackUrl: ?string,
     *     maxSteps: ?int,
     *     additionalSteps: ?int,
     *     effort: ?string
     * } $prepared
     */
    private function send(ApiKeyContext $context, array $prepared): JsonResponse
    {
        $result = $this->transcribeMedia(
            $context,
            $prepared['mediaId'],
            $prepared['language'],
        );

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $message = $this->message($result, $prepared['extra']);

        if ($message === null) {
            // Nothing to say, and a chat with an empty prompt is a chat
            // the agent cannot answer. Refused before anything is queued.
            return $this->unprocessable('VALIDATION_ERROR', 'Transcription returned no text, so nothing was sent.');
        }

        return $prepared['chatId'] !== null
            ? $this->continueChat($context, $prepared, $message, $result)
            : $this->startChat($context, $prepared, $message, $result);
    }

    /**
     * @param array<string, mixed> $body
     * @return JsonResponse|array{mediaId: string, language: ?string}
     */
    private function prepareTranscribe(array $body): JsonResponse|array
    {
        $mediaId = $this->mediaId($body);

        if ($mediaId === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'media_id is required.');
        }

        if (!$this->isStringField($body, 'language')) {
            return $this->unprocessable('VALIDATION_ERROR', '"language" must be a string when present.');
        }

        return [
            'mediaId' => $mediaId,
            'language' => $this->optionalString($body, 'language'),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return JsonResponse|array{
     *     mediaId: string,
     *     language: ?string,
     *     extra: ?string,
     *     chatId: ?int,
     *     callbackUrl: ?string,
     *     maxSteps: ?int,
     *     additionalSteps: ?int,
     *     effort: ?string
     * }
     * @throws EffortValidationException When the body names an effort the
     *     agent's LLM configuration does not offer
     */
    private function prepareSend(array $body, ApiKeyContext $context): JsonResponse|array
    {
        $mediaId = $this->mediaId($body);

        if ($mediaId === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'media_id is required.');
        }

        $problem = match (true) {
            !$this->isStringField($body, 'language') => '"language" must be a string when present.',
            !$this->isStringField($body, 'text') => '"text" must be a string when present.',
            !$this->isIntField($body, 'chat_id') => '"chat_id" must be an integer when present.',
            $this->callbackUrlRefused($body) => 'callback_url is not an acceptable https URL.',
            default => null,
        };

        if ($problem !== null) {
            return $this->unprocessable('VALIDATION_ERROR', $problem);
        }

        $effort = TaskEffortRequestValidator::parse($body);

        // The effort belongs to the chat this transcript starts, so it is
        // checked against the key's own agent before the recording is even
        // transcribed — a refusal that arrives after the transcription has
        // already cost the caller.
        $this->effortService->assertLevel($effort, $context->requireAgent());

        return [
            'mediaId'         => $mediaId,
            'language'        => $this->optionalString($body, 'language'),
            'extra'           => $this->optionalString($body, 'text'),
            'chatId'          => $this->optionalInt($body, 'chat_id'),
            'callbackUrl'     => $this->stringField($body, 'callback_url'),
            'maxSteps'        => $this->boundedInt($this->intField($body, 'max_steps'), 1, 100),
            'additionalSteps' => $this->boundedInt($this->intField($body, 'additional_steps'), 1, 100),
            'effort'          => $effort,
        ];
    }

    /**
     * Whether the callback URL is one Spora will not call.
     *
     * @param array<string, mixed> $body
     */
    private function callbackUrlRefused(array $body): bool
    {
        $callbackUrl = $this->stringField($body, 'callback_url');

        return $callbackUrl !== null && $this->ssrf->rejectReason($callbackUrl) !== null;
    }

    /**
     * The transcript, or the refusal that keeps the key from reading an
     * asset it may not.
     *
     * The reach is checked before the provider is resolved, so a caller
     * probing for media ids learns nothing about the agent's speech
     * setup.
     *
     * @return TranscriptionResult|JsonResponse
     */
    private function transcribeMedia(
        ApiKeyContext $context,
        string $mediaId,
        ?string $language,
    ): TranscriptionResult|JsonResponse {
        if ($this->media->findMedia($context, $mediaId) === null) {
            return $this->notFound('MEDIA_NOT_FOUND', 'Media asset not found or not accessible.');
        }

        try {
            return $this->transcriber->transcribeReachable(
                $mediaId,
                $context->requireAgentId(),
                $context->requireOwnerUserId(),
                $language,
            );
        } catch (SpeechTranscribeException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }
    }

    /**
     * @param array{
     *     mediaId: string,
     *     language: ?string,
     *     extra: ?string,
     *     chatId: ?int,
     *     callbackUrl: ?string,
     *     maxSteps: ?int,
     *     additionalSteps: ?int,
     *     effort: ?string
     * } $prepared
     */
    private function startChat(
        ApiKeyContext $context,
        array $prepared,
        string $message,
        TranscriptionResult $result,
    ): JsonResponse {
        $task = $this->scope->startChat(
            $context,
            $context->requireAgentId(),
            $message,
            $prepared['maxSteps'],
            [],
            $prepared['effort'],
        );

        // Recorded after the chat exists: a failed insert leaves a queued
        // chat with no callback rather than rolling back work the agent
        // has already been handed.
        $this->callbacks->record(
            $context->key,
            $context->requireAgentId(),
            $task,
            $prepared['callbackUrl'],
        );

        return new JsonResponse([
            'data' => [
                'chat' => $this->projection->status($task),
                'transcription' => $this->transcriptionPayload($prepared['mediaId'], $result),
            ],
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * @param array{
     *     mediaId: string,
     *     language: ?string,
     *     extra: ?string,
     *     chatId: ?int,
     *     callbackUrl: ?string,
     *     maxSteps: ?int,
     *     additionalSteps: ?int,
     *     effort: ?string
     * } $prepared
     */
    private function continueChat(
        ApiKeyContext $context,
        array $prepared,
        string $message,
        TranscriptionResult $result,
    ): JsonResponse {
        try {
            $task = $this->scope->continueChat(
                $context,
                (int) $prepared['chatId'],
                $message,
                $prepared['additionalSteps'],
                [],
                $prepared['effort'],
            );
        } catch (InvalidArgumentException $e) {
            $missing = $this->scope->chatMissing($context, (int) $prepared['chatId']);

            return $missing
                ? $this->notFound('NOT_FOUND', ApiKeyChatAccess::ERR_NOT_FOUND)
                : $this->error('INVALID_STATE', $e->getMessage(), Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'data' => [
                'chat' => $this->projection->status($task),
                'transcription' => $this->transcriptionPayload($prepared['mediaId'], $result),
            ],
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * The transcript, with the caller's own words after it.
     *
     * The transcript leads, because it is what the recording said; the
     * caller's text is the instruction wrapped around it.
     */
    private function message(TranscriptionResult $result, ?string $extra): ?string
    {
        $text = trim($result->text);

        if ($text === '') {
            return null;
        }

        return $extra === null ? $text : $text . "\n\n" . $extra;
    }

    /**
     * @return array{media_id: string, text: string, language: string|null, duration_ms: float|null}
     */
    private function transcriptionPayload(string $mediaId, TranscriptionResult $result): array
    {
        return [
            'media_id'    => $mediaId,
            'text'        => $result->text,
            'language'    => $result->language,
            'duration_ms' => $result->durationMs,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $name): ?string
    {
        $value = $body[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The asset the request names, or null when it names none.
     *
     * @param array<string, mixed> $body
     */
    private function mediaId(array $body): ?string
    {
        $value = $body['media_id'] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A field that may be absent, may be null, and carries nothing when
     * it is blank.
     *
     * @param array<string, mixed> $body
     */
    private function optionalString(array $body, string $name): ?string
    {
        $value = $body[$name] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Whether the field, when it is there, is a string.
     *
     * @param array<string, mixed> $body
     */
    private function isStringField(array $body, string $name): bool
    {
        $value = $body[$name] ?? null;

        return $value === null || is_string($value);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalInt(array $body, string $name): ?int
    {
        $value = $body[$name] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Whether the field, when it is there, is an integer.
     *
     * @param array<string, mixed> $body
     */
    private function isIntField(array $body, string $name): bool
    {
        $value = $body[$name] ?? null;

        return $value === null
            || is_int($value)
            || (is_string($value) && $value !== '' && ctype_digit($value));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function intField(array $body, string $name): ?int
    {
        $value = $body[$name] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
